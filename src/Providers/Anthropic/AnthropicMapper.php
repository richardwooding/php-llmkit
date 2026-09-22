<?php

declare(strict_types=1);

namespace LlmKit\Providers\Anthropic;

use LlmKit\CacheConfig;
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
use stdClass;

/**
 * AnthropicMapper translates llmkit requests and responses to and from the
 * Messages API wire format.
 *
 * @internal
 */
final readonly class AnthropicMapper
{
    public const string BLOCK_TEXT = 'text';
    public const string BLOCK_THINKING = 'thinking';
    public const string BLOCK_REDACTED_THINKING = 'redacted_thinking';
    public const string BLOCK_TOOL_USE = 'tool_use';

    private const string ROLE_USER = 'user';
    private const string ROLE_ASSISTANT = 'assistant';
    private const string BLOCK_IMAGE = 'image';
    private const string BLOCK_DOCUMENT = 'document';
    private const string BLOCK_TOOL_RESULT = 'tool_result';
    private const string SOURCE_BASE64 = 'base64';
    private const string SOURCE_URL = 'url';
    private const string SOURCE_TEXT = 'text';
    private const string MIME_PDF = 'application/pdf';
    private const string MIME_TEXT = 'text/plain';
    private const string THINKING_ENABLED = 'enabled';
    private const string THINKING_ADAPTIVE = 'adaptive';
    private const string DISPLAY_SUMMARIZED = 'summarized';
    private const string SUMMARY_AUTO = 'auto';
    private const string CACHE_EPHEMERAL = 'ephemeral';

    /** MAX_CACHE_BREAKPOINTS is the API's per-request cache_control limit. */
    private const int MAX_CACHE_BREAKPOINTS = 4;

    public function __construct(private string $model, private int $defaultMaxTokens) {}

    /** body builds the JSON request body for a messages call. */
    public function body(Request $request, bool $stream): string
    {
        return Wire::encode($this->wire($request, $stream), $request->providerExtra(AnthropicClient::ID));
    }

    /**
     * countBody keeps only the fields /messages/count_tokens accepts;
     * generation parameters such as max_tokens are rejected there.
     */
    public function countBody(Request $request): string
    {
        $wire = $this->wire($request, false);
        $out = ['model' => $wire['model']];
        foreach (['system', 'messages', 'tools', 'tool_choice', 'thinking'] as $key) {
            if (isset($wire[$key])) {
                $out[$key] = $wire[$key];
            }
        }

        return Json::encode($out);
    }

    /**
     * toResponse maps a decoded messages reply onto a Response.
     *
     * @param array<string,mixed> $data
     */
    public function toResponse(array $data): Response
    {
        $parts = [];
        foreach (Arr::objects($data, 'content') as $block) {
            $part = self::toPart($block);
            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return new Response(
            new Message(Role::Assistant, $parts),
            self::finishReason(Arr::str($data, 'stop_reason')),
            self::usage(Arr::obj($data, 'usage')),
            Arr::str($data, 'id'),
            Arr::str($data, 'model'),
        );
    }

    /** @param array<string,mixed> $block */
    public static function toPart(array $block): ?Part
    {
        return match (Arr::str($block, 'type')) {
            self::BLOCK_TEXT => new TextPart(Arr::str($block, 'text')),
            self::BLOCK_THINKING => new ReasoningPart(
                Arr::str($block, 'thinking'),
                Arr::str($block, 'signature'),
            ),
            self::BLOCK_REDACTED_THINKING => new ReasoningPart(encrypted: Arr::str($block, 'data')),
            self::BLOCK_TOOL_USE => new ToolCall(
                Arr::str($block, 'id'),
                Arr::str($block, 'name'),
                self::toolInput($block['input'] ?? null),
            ),
            default => null,
        };
    }

    /**
     * usage folds the cache counters into the input total: the API reports
     * input_tokens as only the uncached remainder, whereas Usage promises the
     * whole prompt on every provider.
     *
     * @param array<string,mixed> $usage
     */
    public static function usage(array $usage): Usage
    {
        $cacheRead = Arr::int($usage, 'cache_read_input_tokens');
        $cacheWrite = Arr::int($usage, 'cache_creation_input_tokens');
        $input = Arr::int($usage, 'input_tokens') + $cacheRead + $cacheWrite;
        $output = Arr::int($usage, 'output_tokens');

        return new Usage(
            inputTokens: $input,
            outputTokens: $output,
            totalTokens: $input + $output,
            cachedInputTokens: $cacheRead,
            cacheWriteTokens: $cacheWrite,
        );
    }

    public static function finishReason(string $reason): FinishReason
    {
        return match ($reason) {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'max_tokens' => FinishReason::Length,
            'tool_use' => FinishReason::ToolCalls,
            'refusal' => FinishReason::ContentFilter,
            default => FinishReason::Other,
        };
    }

    /** @return array<string,mixed> */
    private function wire(Request $request, bool $stream): array
    {
        [$system, $messages] = $this->messages($request->messages);
        $body = [
            'model' => $this->model,
            'max_tokens' => $request->maxTokens > 0 ? $request->maxTokens : $this->defaultMaxTokens,
        ];
        if ($system !== '') {
            $body['system'] = $system;
        }
        $body['messages'] = $messages;
        if ($request->tools !== []) {
            $body['tools'] = $this->tools($request);
        }
        $choice = self::toolChoice($request);
        if ($choice !== null) {
            $body['tool_choice'] = $choice;
        }
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        if ($request->topP !== null) {
            $body['top_p'] = $request->topP;
        }
        if ($request->stop !== []) {
            $body['stop_sequences'] = $request->stop;
        }
        if ($stream) {
            $body['stream'] = true;
        }
        if ($request->cache !== null) {
            self::applyCache($body, $system, $request->cache);
        }
        [$thinking, $outputConfig] = self::reasoning($request->reasoning);
        if ($thinking !== null) {
            $body['thinking'] = $thinking;
        }
        if ($request->format !== null) {
            $outputConfig ??= [];
            $outputConfig['format'] = self::format($request->format);
        }
        if ($outputConfig !== null && $outputConfig !== []) {
            $body['output_config'] = $outputConfig;
        }

        return $body;
    }

    /**
     * applyCache places cache_control breakpoints in prompt order (tools,
     * system, then the last $cache->turns user-role messages) and stops at the
     * API's limit of four, so the stable prefix is preferred when the caller
     * asks for too many.
     *
     * @param array<string,mixed> $body
     */
    private static function applyCache(array &$body, string $system, CacheConfig $cache): void
    {
        $control = self::cacheControl($cache->ttl);
        $slots = self::MAX_CACHE_BREAKPOINTS;
        if ($cache->tools && isset($body['tools']) && is_array($body['tools']) && $body['tools'] !== []) {
            /** @var list<array<string,mixed>> $tools */
            $tools = $body['tools'];
            $tools[count($tools) - 1]['cache_control'] = $control;
            $body['tools'] = $tools;
            --$slots;
        }
        if ($system !== '') {
            $block = ['type' => self::BLOCK_TEXT, 'text' => $system];
            if ($cache->system) {
                $block['cache_control'] = $control;
                --$slots;
            }
            $body['system'] = [$block];
        }
        $turns = min($cache->turns, $slots);
        if ($turns <= 0 || !is_array($body['messages'])) {
            return;
        }
        /** @var list<array{role:string,content:list<array<string,mixed>>}> $messages */
        $messages = $body['messages'];
        for ($i = count($messages) - 1; $i >= 0 && $turns > 0; --$i) {
            if ($messages[$i]['role'] !== self::ROLE_USER || $messages[$i]['content'] === []) {
                continue;
            }
            $last = count($messages[$i]['content']) - 1;
            $messages[$i]['content'][$last]['cache_control'] = $control;
            --$turns;
        }
        $body['messages'] = $messages;
    }

    /** @return array<string,string> */
    private static function cacheControl(string $ttl): array
    {
        return match ($ttl) {
            '', CacheConfig::TTL_5M => ['type' => self::CACHE_EPHEMERAL],
            CacheConfig::TTL_1H => ['type' => self::CACHE_EPHEMERAL, 'ttl' => CacheConfig::TTL_1H],
            default => throw new InvalidRequestException(sprintf(
                '%s: unsupported cache TTL "%s" (want 5m or 1h)',
                AnthropicClient::ID,
                $ttl,
            )),
        };
    }

    /**
     * reasoning prefers adaptive thinking plus output_config.effort; an
     * explicit budget selects the legacy enabled mode that pre-4.6 models
     * require.
     *
     * @return array{array<string,mixed>|null,array<string,mixed>|null}
     */
    private static function reasoning(?ReasoningConfig $reasoning): array
    {
        if ($reasoning === null) {
            return [null, null];
        }
        // OpenAI's reasoning.summary "auto" means the same as "summarized".
        $display = $reasoning->summary === self::SUMMARY_AUTO ? self::DISPLAY_SUMMARIZED : $reasoning->summary;
        $thinking = $reasoning->budgetTokens > 0
            ? ['type' => self::THINKING_ENABLED, 'budget_tokens' => $reasoning->budgetTokens]
            : ['type' => self::THINKING_ADAPTIVE];
        if ($display !== '') {
            $thinking['display'] = $display;
        }

        return [$thinking, $reasoning->effort === '' ? null : ['effort' => $reasoning->effort]];
    }

    /** @return array<string,mixed> */
    private static function format(ResponseFormat $format): array
    {
        if ($format->type === ResponseFormat::TYPE_JSON_SCHEMA) {
            if ($format->schema === []) {
                throw new InvalidRequestException(
                    AnthropicClient::ID . ': json_schema format requires a schema',
                );
            }

            return ['type' => 'json_schema', 'schema' => $format->schema];
        }
        if ($format->type === ResponseFormat::TYPE_JSON) {
            throw UnsupportedException::forProvider(
                AnthropicClient::ID,
                'json response format without a schema',
            );
        }

        throw new InvalidRequestException(sprintf(
            '%s: unknown response format "%s"',
            AnthropicClient::ID,
            $format->type,
        ));
    }

    /** @return list<array<string,mixed>> */
    private function tools(Request $request): array
    {
        $out = [];
        foreach ($request->tools as $tool) {
            $out[] = Wire::filter([
                'name' => $tool->name,
                'description' => $tool->description === '' ? null : $tool->description,
                'input_schema' => $tool->parameters === [] ? ['type' => 'object'] : $tool->parameters,
                'strict' => $tool->strict ? true : null,
            ]);
        }

        return $out;
    }

    /** @return array<string,string>|null */
    private static function toolChoice(Request $request): ?array
    {
        $choice = $request->toolChoice;
        if ($choice === null) {
            return null;
        }

        return match ($choice->mode) {
            ToolChoiceMode::Auto => ['type' => 'auto'],
            ToolChoiceMode::Required => ['type' => 'any'],
            ToolChoiceMode::None => ['type' => 'none'],
            ToolChoiceMode::Named => ['type' => 'tool', 'name' => $choice->name],
        };
    }

    /**
     * messages hoists system text and merges adjacent same-role turns,
     * because the API rejects consecutive messages with the same role.
     *
     * @param list<Message> $messages
     *
     * @return array{string,list<array<string,mixed>>}
     */
    private function messages(array $messages): array
    {
        $system = [];
        $out = [];
        foreach ($messages as $message) {
            if ($message->role === Role::System) {
                $system[] = $message->text();
                continue;
            }
            $role = $message->role === Role::Assistant ? self::ROLE_ASSISTANT : self::ROLE_USER;
            $content = $this->blocks($message->parts);
            if ($content === []) {
                continue;
            }
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['role'] === $role) {
                /** @var list<array<string,mixed>> $existing */
                $existing = $out[$last]['content'];
                $out[$last] = ['role' => $role, 'content' => array_merge($existing, $content)];
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }

        return [implode("\n\n", $system), array_values($out)];
    }

    /**
     * @param list<Part> $parts
     *
     * @return list<array<string,mixed>>
     */
    private function blocks(array $parts): array
    {
        $out = [];
        foreach ($parts as $part) {
            $block = $this->block($part);
            if ($block !== null) {
                $out[] = $block;
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function block(Part $part): ?array
    {
        if ($part instanceof TextPart) {
            return ['type' => self::BLOCK_TEXT, 'text' => $part->text];
        }
        if ($part instanceof ImagePart) {
            return ['type' => self::BLOCK_IMAGE, 'source' => self::source($part->url, $part->mime, $part->data)];
        }
        if ($part instanceof FilePart) {
            return self::documentBlock($part);
        }
        if ($part instanceof ReasoningPart) {
            return self::thinkingBlock($part);
        }
        if ($part instanceof ToolCall) {
            return [
                'type' => self::BLOCK_TOOL_USE,
                'id' => $part->id,
                'name' => $part->name,
                'input' => self::toolInputArray($part->arguments),
            ];
        }
        if ($part instanceof ToolResult) {
            return $this->toolResultBlock($part);
        }

        throw UnsupportedException::forProvider(AnthropicClient::ID, $part::class . ' input');
    }

    /** @return array<string,string> */
    private static function source(string $url, string $mime, string $data): array
    {
        if ($url !== '') {
            return ['type' => self::SOURCE_URL, 'url' => $url];
        }

        return [
            'type' => self::SOURCE_BASE64,
            'media_type' => Wire::mimeOr($mime, $data),
            'data' => base64_encode($data),
        ];
    }

    /** @return array<string,mixed> */
    private static function documentBlock(FilePart $file): array
    {
        $block = ['type' => self::BLOCK_DOCUMENT];
        if ($file->name !== '') {
            $block['title'] = $file->name;
        }
        if ($file->url !== '') {
            $block['source'] = ['type' => self::SOURCE_URL, 'url' => $file->url];
        } elseif ($file->mime === self::MIME_TEXT) {
            $block['source'] = [
                'type' => self::SOURCE_TEXT,
                'media_type' => self::MIME_TEXT,
                'data' => $file->data,
            ];
        } else {
            $block['source'] = [
                'type' => self::SOURCE_BASE64,
                'media_type' => $file->mime === '' ? self::MIME_PDF : $file->mime,
                'data' => base64_encode($file->data),
            ];
        }

        return $block;
    }

    /** @return array<string,mixed>|null */
    private static function thinkingBlock(ReasoningPart $reasoning): ?array
    {
        if ($reasoning->encrypted !== '') {
            return ['type' => self::BLOCK_REDACTED_THINKING, 'data' => $reasoning->encrypted];
        }
        if ($reasoning->signature === '' && $reasoning->text === '') {
            return null;
        }

        return Wire::filter([
            'type' => self::BLOCK_THINKING,
            'thinking' => $reasoning->text,
            'signature' => $reasoning->signature === '' ? null : $reasoning->signature,
        ]);
    }

    /** @return array<string,mixed> */
    private function toolResultBlock(ToolResult $result): array
    {
        $block = ['type' => self::BLOCK_TOOL_RESULT, 'tool_use_id' => $result->callId];
        if ($result->isError) {
            $block['is_error'] = true;
        }
        $textOnly = true;
        foreach ($result->content as $part) {
            if ($part instanceof TextPart) {
                continue;
            }
            if ($part instanceof ImagePart) {
                $textOnly = false;
                continue;
            }

            throw UnsupportedException::forProvider(
                AnthropicClient::ID,
                $part::class . ' in tool result',
            );
        }
        if ($textOnly) {
            $text = $result->toText();
            if ($text !== '') {
                $block['content'] = $text;
            }

            return $block;
        }
        $block['content'] = $this->blocks($result->content);

        return $block;
    }

    /** @return array<array-key,mixed>|stdClass */
    private static function toolInputArray(string $arguments): array|stdClass
    {
        if ($arguments === '' || !json_validate($arguments)) {
            return new stdClass();
        }
        $decoded = Json::decode($arguments, 'decode tool arguments');
        if (!is_array($decoded)) {
            return new stdClass();
        }

        return $decoded === [] ? new stdClass() : $decoded;
    }

    private static function toolInput(mixed $input): string
    {
        if ($input === null || $input === []) {
            return '{}';
        }

        return is_string($input) ? $input : Json::encode($input);
    }
}
