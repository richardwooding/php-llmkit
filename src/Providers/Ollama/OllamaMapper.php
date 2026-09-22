<?php

declare(strict_types=1);

namespace LlmKit\Providers\Ollama;

use LlmKit\Exception\InvalidRequestException;
use LlmKit\Exception\UnsupportedException;
use LlmKit\FinishReason;
use LlmKit\Http\Wire;
use LlmKit\ImagePart;
use LlmKit\Internal\Arr;
use LlmKit\Internal\Json;
use LlmKit\Message;
use LlmKit\ReasoningPart;
use LlmKit\Request;
use LlmKit\Response;
use LlmKit\ResponseFormat;
use LlmKit\Role;
use LlmKit\TextPart;
use LlmKit\ToolCall;
use LlmKit\ToolChoiceMode;
use LlmKit\Usage;

/**
 * OllamaMapper translates llmkit requests and responses to and from the
 * Ollama /api/chat wire format.
 *
 * @internal
 */
final readonly class OllamaMapper
{
    public function __construct(private string $model, private string $keepAlive) {}

    /** body builds the JSON request body for a chat call. */
    public function body(Request $request, bool $stream): string
    {
        $body = [
            'model' => $this->model,
            'messages' => $this->messages($request->messages),
            'stream' => $stream,
        ];
        if ($request->tools !== [] && $request->toolChoice?->mode !== ToolChoiceMode::None) {
            $body['tools'] = $this->tools($request);
        }
        if ($request->reasoning !== null) {
            $body['think'] = true;
        }
        $format = self::format($request->format);
        if ($format !== null) {
            $body['format'] = $format;
        }
        $options = self::options($request);
        if ($options !== []) {
            $body['options'] = $options;
        }
        if ($this->keepAlive !== '') {
            $body['keep_alive'] = $this->keepAlive;
        }

        return Wire::encode($body, $request->providerExtra(OllamaClient::ID));
    }

    /**
     * embedBody builds the JSON request body for an /api/embed call.
     *
     * @param list<string>        $inputs
     * @param array<string,mixed> $extra
     */
    public function embedBody(int $dimensions, array $inputs, array $extra): string
    {
        $body = ['model' => $this->model, 'input' => $inputs];
        if ($dimensions > 0) {
            $body['dimensions'] = $dimensions;
        }
        if ($this->keepAlive !== '') {
            $body['keep_alive'] = $this->keepAlive;
        }

        return Wire::encode($body, $extra);
    }

    /**
     * toResponse maps a decoded chat reply onto a Response.
     *
     * @param array<string,mixed> $data
     */
    public function toResponse(array $data): Response
    {
        $message = Arr::obj($data, 'message');
        $parts = [];
        if (Arr::str($message, 'thinking') !== '') {
            $parts[] = new ReasoningPart(Arr::str($message, 'thinking'));
        }
        if (Arr::str($message, 'content') !== '') {
            $parts[] = new TextPart(Arr::str($message, 'content'));
        }
        $calls = Arr::objects($message, 'tool_calls');
        foreach ($calls as $i => $call) {
            $parts[] = self::toolCall($call, $i);
        }
        $model = Arr::str($data, 'model');

        return new Response(
            new Message(Role::Assistant, $parts),
            self::finishReason(Arr::str($data, 'done_reason'), $calls !== []),
            self::usage($data),
            model: $model === '' ? $this->model : $model,
        );
    }

    /**
     * toolCall builds a call, synthesising an ID because Ollama sends none.
     * Missing or empty arguments become an empty JSON object.
     *
     * @param array<string,mixed> $call
     */
    public static function toolCall(array $call, int $index): ToolCall
    {
        $function = Arr::obj($call, 'function');
        $id = Arr::str($call, 'id');
        $arguments = Arr::rawJson($function, 'arguments');

        return new ToolCall(
            $id === '' ? 'call_' . ($index + 1) : $id,
            Arr::str($function, 'name'),
            $arguments === '' || $arguments === '[]' ? '{}' : $arguments,
        );
    }

    /** @param array<string,mixed> $data */
    public static function usage(array $data): Usage
    {
        $input = Arr::int($data, 'prompt_eval_count');
        $output = Arr::int($data, 'eval_count');

        return new Usage($input, $output, $input + $output);
    }

    public static function finishReason(string $reason, bool $hasTools): FinishReason
    {
        if ($hasTools) {
            return FinishReason::ToolCalls;
        }

        return match ($reason) {
            'stop', '' => FinishReason::Stop,
            'length' => FinishReason::Length,
            default => FinishReason::Other,
        };
    }

    /** @return array<string,mixed> */
    private static function options(Request $request): array
    {
        return Wire::filter([
            'num_predict' => $request->maxTokens > 0 ? $request->maxTokens : null,
            'temperature' => $request->temperature,
            'top_p' => $request->topP,
            'stop' => $request->stop === [] ? null : $request->stop,
            'seed' => $request->seed,
        ]);
    }

    /** @return array<string,mixed>|string|null */
    private static function format(?ResponseFormat $format): array|string|null
    {
        if ($format === null) {
            return null;
        }
        if ($format->type === ResponseFormat::TYPE_JSON) {
            return 'json';
        }
        if ($format->type === ResponseFormat::TYPE_JSON_SCHEMA) {
            if ($format->schema === []) {
                throw new InvalidRequestException(
                    OllamaClient::ID . ': json_schema format requires a schema',
                );
            }

            return $format->schema;
        }

        throw new InvalidRequestException(sprintf(
            '%s: unknown response format "%s"',
            OllamaClient::ID,
            $format->type,
        ));
    }

    /** @return list<array<string,mixed>> */
    private function tools(Request $request): array
    {
        $out = [];
        foreach ($request->tools as $tool) {
            $out[] = ['type' => 'function', 'function' => Wire::filter([
                'name' => $tool->name,
                'description' => $tool->description === '' ? null : $tool->description,
                'parameters' => $tool->parameters === [] ? null : $tool->parameters,
            ])];
        }

        return $out;
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
                    $out[] = ['role' => 'system', 'content' => $message->text()];
                    break;
                case Role::User:
                    $out[] = $this->userMessage($message);
                    break;
                case Role::Assistant:
                    $out[] = $this->assistantMessage($message);
                    break;
                case Role::Tool:
                    foreach ($message->toolResults() as $result) {
                        foreach ($result->content as $part) {
                            if (!$part instanceof TextPart) {
                                throw UnsupportedException::forProvider(
                                    OllamaClient::ID,
                                    $part::class . ' in tool result',
                                );
                            }
                        }
                        $out[] = Wire::filter([
                            'role' => 'tool',
                            'content' => $result->toText(),
                            'tool_name' => $result->name === '' ? null : $result->name,
                        ]);
                    }
                    break;
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function userMessage(Message $message): array
    {
        $text = '';
        $images = [];
        foreach ($message->parts as $part) {
            if ($part instanceof TextPart) {
                $text .= $part->text;
                continue;
            }
            if ($part instanceof ImagePart) {
                if ($part->url !== '') {
                    throw UnsupportedException::forProvider(
                        OllamaClient::ID,
                        'image by URL (supply bytes)',
                    );
                }
                $images[] = base64_encode($part->data);
                continue;
            }

            throw UnsupportedException::forProvider(OllamaClient::ID, $part::class . ' input');
        }
        $out = ['role' => 'user', 'content' => $text];
        if ($images !== []) {
            $out['images'] = $images;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function assistantMessage(Message $message): array
    {
        $text = '';
        $thinking = '';
        $calls = [];
        foreach ($message->parts as $part) {
            if ($part instanceof TextPart) {
                $text .= $part->text;
            } elseif ($part instanceof ReasoningPart) {
                $thinking = $part->text;
            } elseif ($part instanceof ToolCall) {
                $calls[] = ['function' => [
                    'name' => $part->name,
                    'arguments' => Json::decode($part->argumentsJson(), 'decode tool arguments'),
                ]];
            }
        }
        $out = ['role' => 'assistant', 'content' => $text];
        if ($thinking !== '') {
            $out['thinking'] = $thinking;
        }
        if ($calls !== []) {
            $out['tool_calls'] = $calls;
        }

        return $out;
    }
}
