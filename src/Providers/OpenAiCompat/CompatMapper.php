<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenAiCompat;

use LlmKit\AudioPart;
use LlmKit\Exception\InvalidRequestException;
use LlmKit\Exception\UnsupportedException;
use LlmKit\FilePart;
use LlmKit\FinishReason;
use LlmKit\Http\Wire;
use LlmKit\ImagePart;
use LlmKit\Internal\Arr;
use LlmKit\Internal\Json;
use LlmKit\Message;
use LlmKit\Part;
use LlmKit\ReasoningConfig;
use LlmKit\ReasoningPart;
use LlmKit\Request;
use LlmKit\Response;
use LlmKit\ResponseFormat;
use LlmKit\Role;
use LlmKit\TextPart;
use LlmKit\ToolCall;
use LlmKit\ToolChoiceMode;
use LlmKit\ToolResult;
use LlmKit\Usage;

/**
 * CompatMapper translates llmkit requests and responses to and from the
 * OpenAI Chat Completions wire format.
 *
 * @internal
 */
final readonly class CompatMapper
{
    private const string TYPE_FUNCTION = 'function';

    public function __construct(private EndpointConfig $config, private string $model) {}

    /** body builds the JSON request body for a chat or streaming call. */
    public function body(Request $request, bool $stream): string
    {
        $validate = $this->config->quirks->validate;
        if ($validate !== null) {
            $validate($this->model, $request);
        }
        $quirks = $this->config->quirks;
        $body = [
            'model' => $this->model,
            'messages' => $this->messages($request->messages),
        ];
        if ($request->tools !== []) {
            $body['tools'] = $this->tools($request);
        }
        $choice = $this->toolChoice($request);
        if ($choice !== null) {
            $body['tool_choice'] = $choice;
        }
        if ($request->maxTokens > 0) {
            $body[$quirks->maxCompletionTokens ? 'max_completion_tokens' : 'max_tokens'] = $request->maxTokens;
        }
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        if ($request->topP !== null) {
            $body['top_p'] = $request->topP;
        }
        if ($request->stop !== []) {
            $body['stop'] = $request->stop;
        }
        if ($quirks->seed && $request->seed !== null) {
            $body['seed'] = $request->seed;
        }
        if ($stream) {
            $body['stream'] = true;
            if ($quirks->streamUsage) {
                $body['stream_options'] = ['include_usage' => true];
            }
        }
        $format = $this->responseFormat($request->format);
        if ($format !== null) {
            $body['response_format'] = $format;
        }

        return Wire::encode($body, [
            ...$this->reasoning($request->reasoning),
            ...$request->providerExtra($this->config->id),
        ]);
    }

    /**
     * embedBody builds the JSON request body for an embeddings call.
     *
     * @param list<string>        $inputs
     * @param array<string,mixed> $extra
     */
    public function embedBody(string $model, array $inputs, int $dimensions, array $extra): string
    {
        $body = ['model' => $model, 'input' => $inputs, 'encoding_format' => 'float'];
        if ($dimensions > 0) {
            $body['dimensions'] = $dimensions;
        }

        return Wire::encode($body, $extra);
    }

    /**
     * toResponse maps a decoded chat completion onto a Response.
     *
     * @param array<string,mixed> $data
     */
    public function toResponse(array $data): Response
    {
        $usage = $this->usageOf($data);
        $choices = Arr::objects($data, 'choices');
        if ($choices === []) {
            return new Response(
                new Message(Role::Assistant),
                FinishReason::Other,
                $usage ?? new Usage(),
                Arr::str($data, 'id'),
                Arr::str($data, 'model'),
            );
        }
        $choice = $choices[0];
        $message = Arr::obj($choice, 'message');
        $parts = [];
        $reasoning = $this->reasoningText($message);
        if ($reasoning !== '') {
            $parts[] = new ReasoningPart($reasoning);
        }
        $text = $this->contentText($message);
        if ($text !== '') {
            $parts[] = new TextPart($text);
        }
        $refusal = Arr::str($message, 'refusal');
        if ($refusal !== '') {
            $parts[] = new TextPart($refusal);
        }
        $calls = Arr::objects($message, 'tool_calls');
        foreach ($calls as $call) {
            $function = Arr::obj($call, 'function');
            $parts[] = new ToolCall(
                Arr::str($call, 'id'),
                Arr::str($function, 'name'),
                self::rawArguments(Arr::str($function, 'arguments')),
            );
        }

        return new Response(
            new Message(Role::Assistant, $parts),
            self::finishReason(Arr::str($choice, 'finish_reason'), $calls !== []),
            $usage ?? new Usage(),
            Arr::str($data, 'id'),
            Arr::str($data, 'model'),
        );
    }

    /**
     * reasoningText reads the provider's thinking field out of an assistant
     * message or delta.
     *
     * @param array<string,mixed> $message
     */
    public function reasoningText(array $message): string
    {
        $field = $this->config->quirks->reasoningContentField;

        return $field === '' ? '' : Arr::str($message, $field);
    }

    /**
     * usageOf reads the usage object, including Groq's x_groq envelope.
     *
     * @param array<string,mixed> $data
     */
    public function usageOf(array $data): ?Usage
    {
        $usage = Arr::obj($data, 'usage');
        if ($usage === []) {
            $usage = Arr::obj(Arr::obj($data, 'x_groq'), 'usage');
        }

        return $usage === [] ? null : self::usage($usage);
    }

    /** @param array<string,mixed> $usage */
    public static function usage(array $usage): Usage
    {
        $input = Arr::int($usage, 'prompt_tokens');
        $output = Arr::int($usage, 'completion_tokens');
        $total = Arr::int($usage, 'total_tokens');

        return new Usage(
            inputTokens: $input,
            outputTokens: $output,
            totalTokens: $total === 0 ? $input + $output : $total,
            cachedInputTokens: Arr::int(Arr::obj($usage, 'prompt_tokens_details'), 'cached_tokens'),
            reasoningTokens: Arr::int(Arr::obj($usage, 'completion_tokens_details'), 'reasoning_tokens'),
        );
    }

    /** finishReason maps the wire reason, promoting "stop" when tools were called. */
    public static function finishReason(string $reason, bool $hasTools): FinishReason
    {
        return match ($reason) {
            'stop', '' => $hasTools ? FinishReason::ToolCalls : FinishReason::Stop,
            'length' => FinishReason::Length,
            'tool_calls', 'function_call' => FinishReason::ToolCalls,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Other,
        };
    }

    /** rawArguments keeps valid JSON as is and quotes anything else. */
    public static function rawArguments(string $arguments): string
    {
        if ($arguments === '') {
            return '{}';
        }
        if (json_validate($arguments)) {
            return $arguments;
        }

        return Json::encode($arguments);
    }

    /** @param array<string,mixed> $message */
    private function contentText(array $message): string
    {
        $content = $message['content'] ?? null;

        return match (true) {
            is_string($content) => $content,
            $content === null => '',
            default => Json::encode($content),
        };
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<array<string,mixed>>
     */
    private function messages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            switch ($message->role) {
                case Role::System:
                    $out[] = Wire::filter([
                        'role' => $this->config->quirks->developerRole ? 'developer' : 'system',
                        'content' => $message->text(),
                        'name' => $message->name === '' ? null : $message->name,
                    ]);
                    break;
                case Role::User:
                    $out[] = Wire::filter([
                        'role' => 'user',
                        'content' => $this->userContent($message->parts),
                        'name' => $message->name === '' ? null : $message->name,
                    ]);
                    break;
                case Role::Assistant:
                    $out[] = $this->assistantMessage($message);
                    break;
                case Role::Tool:
                    foreach ($this->toolMessages($message) as $entry) {
                        $out[] = $entry;
                    }
                    break;
            }
        }

        return $out;
    }

    /**
     * @param list<Part> $parts
     *
     * @return string|list<array<string,mixed>>
     */
    private function userContent(array $parts): string|array
    {
        if (count($parts) === 1 && $parts[0] instanceof TextPart) {
            return $parts[0]->text;
        }
        $out = [];
        foreach ($parts as $part) {
            $out[] = $this->contentPart($part);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function contentPart(Part $part): array
    {
        $quirks = $this->config->quirks;
        if ($part instanceof TextPart) {
            return ['type' => 'text', 'text' => $part->text];
        }
        if ($part instanceof ImagePart) {
            if (!$quirks->images) {
                throw UnsupportedException::forProvider($this->config->id, 'image input');
            }
            $url = $part->url !== '' ? $part->url : Wire::dataUri($part->mime, $part->data);

            return ['type' => 'image_url', 'image_url' => Wire::filter([
                'url' => $url,
                'detail' => $part->detail === '' ? null : $part->detail,
            ])];
        }
        if ($part instanceof AudioPart) {
            if (!$quirks->audio) {
                throw UnsupportedException::forProvider($this->config->id, 'audio input');
            }

            return ['type' => 'input_audio', 'input_audio' => [
                'data' => base64_encode($part->data),
                'format' => self::audioFormat($part->mime),
            ]];
        }
        if ($part instanceof FilePart) {
            if (!$quirks->files) {
                throw UnsupportedException::forProvider($this->config->id, 'file input');
            }

            return ['type' => 'file', 'file' => Wire::filter([
                'filename' => $part->name === '' ? null : $part->name,
                'file_data' => $part->url !== '' ? $part->url : Wire::dataUri($part->mime, $part->data),
            ])];
        }

        throw UnsupportedException::forProvider(
            $this->config->id,
            $part::class . ' in user message',
        );
    }

    private static function audioFormat(string $mime): string
    {
        $slash = strpos($mime, '/');
        $sub = $slash === false ? $mime : substr($mime, $slash + 1);

        return match ($sub) {
            'wav', 'x-wav', 'wave' => 'wav',
            'mpeg', 'mp3' => 'mp3',
            default => $sub,
        };
    }

    /** @return array<string,mixed> */
    private function assistantMessage(Message $message): array
    {
        $text = '';
        $calls = [];
        foreach ($message->parts as $part) {
            if ($part instanceof TextPart) {
                $text .= $part->text;
            } elseif ($part instanceof ToolCall) {
                $calls[] = [
                    'id' => $part->id,
                    'type' => self::TYPE_FUNCTION,
                    self::TYPE_FUNCTION => [
                        'name' => $part->name,
                        'arguments' => $part->argumentsJson(),
                    ],
                ];
            }
        }
        $out = ['role' => 'assistant'];
        if ($message->name !== '') {
            $out['name'] = $message->name;
        }
        // Reasoning is never echoed back: DeepSeek rejects its own reasoning_content.
        if ($text !== '' || $calls === []) {
            $out['content'] = $text;
        }
        if ($calls !== []) {
            $out['tool_calls'] = $calls;
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function toolMessages(Message $message): array
    {
        $out = [];
        foreach ($message->parts as $part) {
            if (!$part instanceof ToolResult) {
                throw new InvalidRequestException(sprintf(
                    '%s: tool message may only contain tool results, got %s',
                    $this->config->id,
                    $part::class,
                ));
            }
            foreach ($part->content as $inner) {
                if (!$inner instanceof TextPart) {
                    throw UnsupportedException::forProvider(
                        $this->config->id,
                        $inner::class . ' in tool result',
                    );
                }
            }
            $out[] = ['role' => 'tool', 'tool_call_id' => $part->callId, 'content' => $part->toText()];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function tools(Request $request): array
    {
        $out = [];
        foreach ($request->tools as $tool) {
            $function = ['name' => $tool->name];
            if ($tool->description !== '') {
                $function['description'] = $tool->description;
            }
            if ($tool->parameters !== []) {
                $function['parameters'] = $tool->parameters;
            }
            if ($tool->strict && $this->config->quirks->strict) {
                $function['strict'] = true;
            }
            $out[] = ['type' => self::TYPE_FUNCTION, self::TYPE_FUNCTION => $function];
        }

        return $out;
    }

    /** @return array<string,mixed>|string|null */
    private function toolChoice(Request $request): array|string|null
    {
        $choice = $request->toolChoice;
        if ($choice === null || $choice->mode === ToolChoiceMode::Auto) {
            return null;
        }
        if ($choice->mode === ToolChoiceMode::Named) {
            return ['type' => self::TYPE_FUNCTION, self::TYPE_FUNCTION => ['name' => $choice->name]];
        }

        return $choice->mode->value;
    }

    /** @return array<string,mixed>|null */
    private function responseFormat(?ResponseFormat $format): ?array
    {
        if ($format === null) {
            return null;
        }
        if ($format->type === ResponseFormat::TYPE_JSON) {
            return ['type' => 'json_object'];
        }
        if ($format->type === ResponseFormat::TYPE_JSON_SCHEMA) {
            if (!$this->config->quirks->jsonSchema) {
                throw UnsupportedException::forProvider($this->config->id, 'json_schema response format');
            }

            return ['type' => 'json_schema', 'json_schema' => Wire::filter([
                'name' => $format->name,
                'schema' => $format->schema === [] ? null : $format->schema,
                'strict' => $format->strict ? true : null,
            ])];
        }

        throw new InvalidRequestException(sprintf(
            '%s: unknown response format "%s"',
            $this->config->id,
            $format->type,
        ));
    }

    /** @return array<string,mixed> */
    private function reasoning(?ReasoningConfig $reasoning): array
    {
        if ($reasoning === null) {
            return [];
        }
        $custom = $this->config->quirks->reasoningRequest;
        if ($custom !== null) {
            return $custom($reasoning);
        }
        if ($reasoning->effort === '') {
            return [];
        }

        return ['reasoning_effort' => $reasoning->effort];
    }
}
