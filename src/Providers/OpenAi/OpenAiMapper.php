<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenAi;

use LlmKit\AudioPart;
use LlmKit\Exception\ApiException;
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
 * OpenAiMapper translates llmkit requests and responses to and from the
 * Responses API wire format.
 *
 * @internal
 */
final readonly class OpenAiMapper
{
    public const string TYPE_MESSAGE = 'message';
    public const string TYPE_FUNCTION_CALL = 'function_call';
    public const string TYPE_REASONING = 'reasoning';

    private const string TYPE_FUNCTION = 'function';
    private const string TYPE_FUNCTION_CALL_OUTPUT = 'function_call_output';
    private const string TYPE_OUTPUT_TEXT = 'output_text';
    private const string TYPE_REFUSAL = 'refusal';
    private const string STATUS_FAILED = 'failed';
    private const string STATUS_INCOMPLETE = 'incomplete';
    private const string INCLUDE_ENCRYPTED_REASONING = 'reasoning.encrypted_content';
    private const string SUMMARY_AUTO = 'auto';
    private const string DISPLAY_SUMMARIZED = 'summarized';
    private const string SEPARATOR = "\n\n";

    public function __construct(private string $model) {}

    /** body builds the JSON request body for a Responses API call. */
    public function body(Request $request, bool $stream): string
    {
        [$instructions, $input] = $this->items($request->messages);
        $body = ['model' => $this->model];
        if ($instructions !== '') {
            $body['instructions'] = $instructions;
        }
        $body['input'] = $input;
        if ($request->tools !== []) {
            $body['tools'] = $this->tools($request);
        }
        $choice = self::toolChoice($request);
        if ($choice !== null) {
            $body['tool_choice'] = $choice;
        }
        if ($request->maxTokens > 0) {
            $body['max_output_tokens'] = $request->maxTokens;
        }
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        if ($request->topP !== null) {
            $body['top_p'] = $request->topP;
        }
        $format = self::textFormat($request->format);
        if ($format !== null) {
            $body['text'] = $format;
        }
        if ($request->reasoning !== null) {
            // Ask for encrypted reasoning so later turns can replay the blocks.
            $body['include'] = [self::INCLUDE_ENCRYPTED_REASONING];
            $reasoning = self::reasoning($request->reasoning);
            if ($reasoning !== null) {
                $body['reasoning'] = $reasoning;
            }
        }
        if ($stream) {
            $body['stream'] = true;
        }

        return Wire::encode($body, $request->providerExtra(OpenAiClient::ID));
    }

    /**
     * toResponse maps a decoded Responses API reply onto a Response.
     *
     * @param array<string,mixed> $data
     */
    public function toResponse(array $data, string $raw): Response
    {
        if (Arr::str($data, 'status') === self::STATUS_FAILED) {
            throw self::failure($data, $raw);
        }
        $parts = [];
        $hasCalls = false;
        foreach (Arr::objects($data, 'output') as $item) {
            foreach (self::outputParts($item) as $part) {
                $parts[] = $part;
            }
            if (Arr::str($item, 'type') === self::TYPE_FUNCTION_CALL) {
                $hasCalls = true;
            }
        }
        $usage = Arr::obj($data, 'usage');

        return new Response(
            new Message(Role::Assistant, $parts),
            self::finishReason($data, $hasCalls),
            $usage === [] ? new Usage() : self::usage($usage),
            Arr::str($data, 'id'),
            Arr::str($data, 'model'),
        );
    }

    /**
     * finishReason maps a response status onto a finish reason.
     *
     * @param array<string,mixed> $data
     */
    public static function finishReason(array $data, bool $hasTools): FinishReason
    {
        if ($hasTools) {
            return FinishReason::ToolCalls;
        }
        if (Arr::str($data, 'status') !== self::STATUS_INCOMPLETE) {
            return FinishReason::Stop;
        }

        return match (Arr::str(Arr::obj($data, 'incomplete_details'), 'reason')) {
            'max_output_tokens', 'max_tokens' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Other,
        };
    }

    /**
     * hasFunctionCall reports whether the output contains a tool call.
     *
     * @param array<string,mixed> $data
     */
    public static function hasFunctionCall(array $data): bool
    {
        foreach (Arr::objects($data, 'output') as $item) {
            if (Arr::str($item, 'type') === self::TYPE_FUNCTION_CALL) {
                return true;
            }
        }

        return false;
    }

    /**
     * failure turns a failed response into an ApiException.
     *
     * @param array<string,mixed> $data
     */
    public static function failure(array $data, string $raw): ApiException
    {
        $error = Arr::obj($data, 'error');
        $message = Arr::str($error, 'message');

        return ApiException::create(
            provider: OpenAiClient::ID,
            errorCode: Arr::str($error, 'code'),
            detail: $message === '' ? 'response failed' : $message,
            body: $raw,
        );
    }

    /** @param array<string,mixed> $usage */
    public static function usage(array $usage): Usage
    {
        $input = Arr::int($usage, 'input_tokens');
        $output = Arr::int($usage, 'output_tokens');
        $total = Arr::int($usage, 'total_tokens');

        return new Usage(
            inputTokens: $input,
            outputTokens: $output,
            totalTokens: $total === 0 ? $input + $output : $total,
            cachedInputTokens: Arr::int(Arr::obj($usage, 'input_tokens_details'), 'cached_tokens'),
            reasoningTokens: Arr::int(Arr::obj($usage, 'output_tokens_details'), 'reasoning_tokens'),
        );
    }

    /** rawArguments keeps valid JSON as is and quotes anything else. */
    public static function rawArguments(string $arguments): string
    {
        if ($arguments === '') {
            return '{}';
        }

        return json_validate($arguments) ? $arguments : Json::encode($arguments);
    }

    /**
     * @param array<string,mixed> $item
     *
     * @return list<Part>
     */
    private static function outputParts(array $item): array
    {
        return match (Arr::str($item, 'type')) {
            self::TYPE_MESSAGE => self::messageParts($item),
            self::TYPE_FUNCTION_CALL => [new ToolCall(
                Arr::str($item, 'call_id'),
                Arr::str($item, 'name'),
                self::rawArguments(Arr::str($item, 'arguments')),
            )],
            self::TYPE_REASONING => self::reasoningParts($item),
            default => [],
        };
    }

    /**
     * @param array<string,mixed> $item
     *
     * @return list<Part>
     */
    private static function messageParts(array $item): array
    {
        $parts = [];
        foreach (Arr::objects($item, 'content') as $content) {
            $type = Arr::str($content, 'type');
            if ($type === self::TYPE_OUTPUT_TEXT && Arr::str($content, 'text') !== '') {
                $parts[] = new TextPart(Arr::str($content, 'text'));
            } elseif ($type === self::TYPE_REFUSAL && Arr::str($content, 'refusal') !== '') {
                $parts[] = new TextPart(Arr::str($content, 'refusal'));
            }
        }

        return $parts;
    }

    /**
     * @param array<string,mixed> $item
     *
     * @return list<Part>
     */
    private static function reasoningParts(array $item): array
    {
        $texts = [];
        foreach (Arr::objects($item, 'summary') as $summary) {
            if (Arr::str($summary, 'text') !== '') {
                $texts[] = Arr::str($summary, 'text');
            }
        }
        $part = new ReasoningPart(
            implode(self::SEPARATOR, $texts),
            Arr::str($item, 'id'),
            Arr::str($item, 'encrypted_content'),
        );

        return $part->text === '' && $part->encrypted === '' ? [] : [$part];
    }

    /**
     * items splits the conversation into instructions and input items.
     *
     * @param list<Message> $messages
     *
     * @return array{string,list<array<string,mixed>>}
     */
    private function items(array $messages): array
    {
        $system = [];
        $out = [];
        foreach ($messages as $message) {
            switch ($message->role) {
                case Role::System:
                    $system[] = $message->text();
                    break;
                case Role::User:
                    $out[] = ['type' => self::TYPE_MESSAGE, 'role' => 'user', 'content' => $this->userContent($message)];
                    break;
                case Role::Assistant:
                    foreach ($this->assistantItems($message) as $item) {
                        $out[] = $item;
                    }
                    break;
                case Role::Tool:
                    foreach ($this->toolItems($message) as $item) {
                        $out[] = $item;
                    }
                    break;
            }
        }

        return [implode(self::SEPARATOR, $system), $out];
    }

    /** @return list<array<string,mixed>> */
    private function userContent(Message $message): array
    {
        $out = [];
        foreach ($message->parts as $part) {
            $out[] = $this->contentPart($part);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function contentPart(Part $part): array
    {
        if ($part instanceof TextPart) {
            return ['type' => 'input_text', 'text' => $part->text];
        }
        if ($part instanceof ImagePart) {
            return Wire::filter([
                'type' => 'input_image',
                'image_url' => $part->url !== '' ? $part->url : Wire::dataUri($part->mime, $part->data),
                'detail' => $part->detail === '' ? null : $part->detail,
            ]);
        }
        if ($part instanceof FilePart) {
            if ($part->url !== '') {
                return ['type' => 'input_file', 'file_url' => $part->url];
            }

            return Wire::filter([
                'type' => 'input_file',
                'filename' => $part->name === '' ? null : $part->name,
                'file_data' => Wire::dataUri($part->mime, $part->data),
            ]);
        }
        if ($part instanceof AudioPart) {
            throw UnsupportedException::forProvider(OpenAiClient::ID, 'audio input');
        }

        throw UnsupportedException::forProvider(OpenAiClient::ID, $part::class . ' in user message');
    }

    /** @return list<array<string,mixed>> */
    private function assistantItems(Message $message): array
    {
        $out = [];
        $text = '';
        foreach ($message->parts as $part) {
            if ($part instanceof TextPart) {
                $text .= $part->text;
                continue;
            }
            $item = $this->assistantItem($part);
            if ($item === null) {
                continue;
            }
            if ($text !== '') {
                $out[] = self::assistantText($text);
                $text = '';
            }
            $out[] = $item;
        }
        if ($text !== '') {
            $out[] = self::assistantText($text);
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function assistantItem(Part $part): ?array
    {
        if ($part instanceof ReasoningPart) {
            // Only encrypted reasoning can be replayed; summaries alone are dropped.
            if ($part->encrypted === '') {
                return null;
            }

            return Wire::filter([
                'type' => self::TYPE_REASONING,
                'id' => $part->signature === '' ? null : $part->signature,
                'encrypted_content' => $part->encrypted,
                'summary' => [],
            ]);
        }
        if ($part instanceof ToolCall) {
            return [
                'type' => self::TYPE_FUNCTION_CALL,
                'call_id' => $part->id,
                'name' => $part->name,
                'arguments' => $part->argumentsJson(),
            ];
        }

        return null;
    }

    /** @return array<string,mixed> */
    private static function assistantText(string $text): array
    {
        return [
            'type' => self::TYPE_MESSAGE,
            'role' => 'assistant',
            'content' => [['type' => self::TYPE_OUTPUT_TEXT, 'text' => $text]],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function toolItems(Message $message): array
    {
        $out = [];
        foreach ($message->parts as $part) {
            if (!$part instanceof ToolResult) {
                throw new InvalidRequestException(sprintf(
                    '%s: tool message may only contain tool results, got %s',
                    OpenAiClient::ID,
                    $part::class,
                ));
            }
            foreach ($part->content as $inner) {
                if (!$inner instanceof TextPart) {
                    throw UnsupportedException::forProvider(
                        OpenAiClient::ID,
                        $inner::class . ' in tool result',
                    );
                }
            }
            $out[] = [
                'type' => self::TYPE_FUNCTION_CALL_OUTPUT,
                'call_id' => $part->callId,
                'output' => $part->toText(),
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function tools(Request $request): array
    {
        $out = [];
        foreach ($request->tools as $tool) {
            $out[] = Wire::filter([
                'type' => self::TYPE_FUNCTION,
                'name' => $tool->name,
                'description' => $tool->description === '' ? null : $tool->description,
                'parameters' => $tool->parameters === [] ? null : $tool->parameters,
                'strict' => $tool->strict ? true : null,
            ]);
        }

        return $out;
    }

    /** @return array<string,string>|string|null */
    private static function toolChoice(Request $request): array|string|null
    {
        $choice = $request->toolChoice;
        if ($choice === null || $choice->mode === ToolChoiceMode::Auto) {
            return null;
        }
        if ($choice->mode === ToolChoiceMode::Named) {
            return ['type' => self::TYPE_FUNCTION, 'name' => $choice->name];
        }

        return $choice->mode->value;
    }

    /** @return array<string,mixed>|null */
    private static function textFormat(?ResponseFormat $format): ?array
    {
        if ($format === null) {
            return null;
        }
        if ($format->type === ResponseFormat::TYPE_JSON) {
            return ['format' => ['type' => 'json_object']];
        }
        if ($format->type === ResponseFormat::TYPE_JSON_SCHEMA) {
            return ['format' => Wire::filter([
                'type' => 'json_schema',
                'name' => $format->name,
                'schema' => $format->schema === [] ? null : $format->schema,
                'strict' => $format->strict ? true : null,
            ])];
        }

        throw new InvalidRequestException(sprintf(
            '%s: unknown response format "%s"',
            OpenAiClient::ID,
            $format->type,
        ));
    }

    /** @return array<string,mixed>|null */
    private static function reasoning(?ReasoningConfig $reasoning): ?array
    {
        if ($reasoning === null) {
            return null;
        }
        // Anthropic's "summarized" means the same as OpenAI's "auto".
        $summary = $reasoning->summary === self::DISPLAY_SUMMARIZED ? self::SUMMARY_AUTO : $reasoning->summary;
        if ($reasoning->effort === '' && $summary === '') {
            return null;
        }

        return Wire::filter([
            'effort' => $reasoning->effort === '' ? null : $reasoning->effort,
            'summary' => $summary === '' ? null : $summary,
        ]);
    }
}
