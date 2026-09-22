<?php

declare(strict_types=1);

namespace LlmKit\Providers\Cohere;

use LlmKit\EmbedInputType;
use LlmKit\Exception\InvalidRequestException;
use LlmKit\Exception\UnsupportedException;
use LlmKit\FinishReason;
use LlmKit\Http\Wire;
use LlmKit\ImagePart;
use LlmKit\Internal\Arr;
use LlmKit\Internal\Json;
use LlmKit\Message;
use LlmKit\Part;
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
 * CohereMapper translates llmkit requests and responses to and from the
 * Cohere v2 chat wire format.
 *
 * @internal
 */
final readonly class CohereMapper
{
    public const string TYPE_TEXT = 'text';
    public const string TYPE_THINKING = 'thinking';

    private const string TYPE_FUNCTION = 'function';
    private const string TYPE_IMAGE_URL = 'image_url';
    private const string TYPE_DOCUMENT = 'document';

    public function __construct(private string $model) {}

    /** body builds the JSON request body for a chat call. */
    public function body(Request $request, bool $stream): string
    {
        $body = ['model' => $this->model, 'messages' => $this->messages($request->messages)];
        if ($request->tools !== []) {
            $body['tools'] = $this->tools($request);
        }
        $choice = self::toolChoice($request);
        if ($choice !== '') {
            $body['tool_choice'] = $choice;
        }
        if ($request->maxTokens > 0) {
            $body['max_tokens'] = $request->maxTokens;
        }
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        if ($request->topP !== null) {
            $body['p'] = $request->topP;
        }
        if ($request->stop !== []) {
            $body['stop_sequences'] = $request->stop;
        }
        if ($request->seed !== null) {
            $body['seed'] = $request->seed;
        }
        $format = self::responseFormat($request->format);
        if ($format !== null) {
            $body['response_format'] = $format;
        }
        if ($request->reasoning !== null) {
            $body['thinking'] = Wire::filter([
                'type' => 'enabled',
                'token_budget' => $request->reasoning->budgetTokens > 0
                    ? $request->reasoning->budgetTokens
                    : null,
            ]);
        }
        if ($stream) {
            $body['stream'] = true;
        }

        return Wire::encode($body, $request->providerExtra(CohereClient::ID));
    }

    /**
     * embedBody builds the JSON request body for an /embed call.
     *
     * @param list<string>        $inputs
     * @param array<string,mixed> $extra
     */
    public function embedBody(array $inputs, ?EmbedInputType $inputType, int $dimensions, array $extra): string
    {
        return Wire::encode(Wire::filter([
            'model' => $this->model,
            'texts' => $inputs,
            'input_type' => $inputType === EmbedInputType::Query ? 'search_query' : 'search_document',
            'embedding_types' => ['float'],
            'output_dimension' => $dimensions > 0 ? $dimensions : null,
            'truncate' => 'END',
        ]), $extra);
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
        if (Arr::str($message, 'tool_plan') !== '') {
            $parts[] = new ReasoningPart(Arr::str($message, 'tool_plan'));
        }
        foreach (Arr::objects($message, 'content') as $block) {
            $part = match (Arr::str($block, 'type')) {
                self::TYPE_TEXT => new TextPart(Arr::str($block, 'text')),
                self::TYPE_THINKING => new ReasoningPart(Arr::str($block, 'thinking')),
                default => null,
            };
            if ($part !== null) {
                $parts[] = $part;
            }
        }
        foreach (Arr::objects($message, 'tool_calls') as $call) {
            $function = Arr::obj($call, 'function');
            $parts[] = new ToolCall(
                Arr::str($call, 'id'),
                Arr::str($function, 'name'),
                self::rawArguments(Arr::str($function, 'arguments')),
            );
        }

        return new Response(
            new Message(Role::Assistant, $parts),
            self::finishReason(Arr::str($data, 'finish_reason')),
            self::usage(Arr::obj($data, 'usage')),
            Arr::str($data, 'id'),
            $this->model,
        );
    }

    /**
     * usage reads Cohere's token counts, which arrive as JSON doubles.
     *
     * @param array<string,mixed> $usage
     */
    public static function usage(array $usage): Usage
    {
        $tokens = Arr::obj($usage, 'tokens');
        if ($tokens === []) {
            $tokens = Arr::obj($usage, 'billed_units');
        }
        $input = (int) Arr::float($tokens, 'input_tokens');
        $output = (int) Arr::float($tokens, 'output_tokens');

        return new Usage(
            inputTokens: $input,
            outputTokens: $output,
            totalTokens: $input + $output,
            cachedInputTokens: (int) Arr::float($usage, 'cached_tokens'),
        );
    }

    public static function finishReason(string $reason): FinishReason
    {
        return match ($reason) {
            'COMPLETE', 'STOP_SEQUENCE' => FinishReason::Stop,
            'MAX_TOKENS' => FinishReason::Length,
            'TOOL_CALL' => FinishReason::ToolCalls,
            default => FinishReason::Other,
        };
    }

    public static function rawArguments(string $arguments): string
    {
        if ($arguments === '') {
            return '{}';
        }

        return json_validate($arguments) ? $arguments : Json::encode($arguments);
    }

    /** @return list<array<string,mixed>> */
    private function tools(Request $request): array
    {
        $out = [];
        foreach ($request->tools as $tool) {
            $out[] = ['type' => self::TYPE_FUNCTION, self::TYPE_FUNCTION => Wire::filter([
                'name' => $tool->name,
                'description' => $tool->description === '' ? null : $tool->description,
                'parameters' => $tool->parameters === [] ? null : $tool->parameters,
            ])];
        }

        return $out;
    }

    private static function toolChoice(Request $request): string
    {
        $choice = $request->toolChoice;
        if ($choice === null) {
            return '';
        }

        return match ($choice->mode) {
            ToolChoiceMode::Auto => '',
            ToolChoiceMode::Required => 'REQUIRED',
            ToolChoiceMode::None => 'NONE',
            ToolChoiceMode::Named => throw UnsupportedException::forProvider(
                CohereClient::ID,
                'named tool choice',
            ),
        };
    }

    /** @return array<string,mixed>|null */
    private static function responseFormat(?ResponseFormat $format): ?array
    {
        if ($format === null) {
            return null;
        }
        if ($format->type === ResponseFormat::TYPE_JSON) {
            return ['type' => 'json_object'];
        }
        if ($format->type === ResponseFormat::TYPE_JSON_SCHEMA) {
            if ($format->schema === []) {
                throw new InvalidRequestException(
                    CohereClient::ID . ': json_schema format requires a schema',
                );
            }

            return ['type' => 'json_object', 'json_schema' => $format->schema];
        }

        throw new InvalidRequestException(sprintf(
            '%s: unknown response format "%s"',
            CohereClient::ID,
            $format->type,
        ));
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
                    $out[] = ['role' => 'user', 'content' => $this->userContent($message->parts)];
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
            if ($part instanceof TextPart) {
                $out[] = ['type' => self::TYPE_TEXT, 'text' => $part->text];
                continue;
            }
            if ($part instanceof ImagePart) {
                $url = $part->url !== '' ? $part->url : Wire::dataUri($part->mime, $part->data);
                $out[] = ['type' => self::TYPE_IMAGE_URL, 'image_url' => ['url' => $url]];
                continue;
            }

            throw UnsupportedException::forProvider(
                CohereClient::ID,
                $part::class . ' in user message',
            );
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function assistantMessage(Message $message): array
    {
        $content = [];
        $calls = [];
        foreach ($message->parts as $part) {
            if ($part instanceof TextPart) {
                $content[] = ['type' => self::TYPE_TEXT, 'text' => $part->text];
            } elseif ($part instanceof ReasoningPart) {
                $content[] = ['type' => self::TYPE_THINKING, 'thinking' => $part->text];
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
        if ($content !== []) {
            $out['content'] = $content;
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
                    CohereClient::ID,
                    $part::class,
                ));
            }
            foreach ($part->content as $inner) {
                if (!$inner instanceof TextPart) {
                    throw UnsupportedException::forProvider(
                        CohereClient::ID,
                        $inner::class . ' in tool result',
                    );
                }
            }
            $out[] = [
                'role' => 'tool',
                'tool_call_id' => $part->callId,
                'content' => [[
                    'type' => self::TYPE_DOCUMENT,
                    'document' => ['data' => [self::TYPE_TEXT => $part->toText()]],
                ]],
            ];
        }

        return $out;
    }
}
