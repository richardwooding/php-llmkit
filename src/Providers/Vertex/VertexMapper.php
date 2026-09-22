<?php

declare(strict_types=1);

namespace LlmKit\Providers\Vertex;

use LlmKit\AudioPart;
use LlmKit\EmbedInputType;
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
 * VertexMapper translates llmkit requests and responses to and from the
 * Gemini generateContent wire format.
 *
 * @internal
 */
final readonly class VertexMapper
{
    private const string ROLE_USER = 'user';
    private const string ROLE_MODEL = 'model';
    private const string MODE_ANY = 'ANY';
    private const string MIME_JSON = 'application/json';

    /** @var array<string,string> */
    private const array MIME_BY_EXTENSION = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'json' => 'application/json',
    ];

    public function __construct(private string $model) {}

    /** body builds the JSON request body for a generateContent call. */
    public function body(Request $request): string
    {
        [$system, $contents] = $this->contents($request->messages);
        $body = ['contents' => $contents];
        if ($system !== null) {
            $body['systemInstruction'] = $system;
        }
        if ($request->tools !== []) {
            $body['tools'] = [['functionDeclarations' => $this->toolDeclarations($request)]];
        }
        $toolConfig = self::toolConfig($request);
        if ($toolConfig !== null) {
            $body['toolConfig'] = $toolConfig;
        }
        $generation = self::generationConfig($request);
        if ($generation !== []) {
            $body['generationConfig'] = $generation;
        }

        return Wire::encode($body, $request->providerExtra(VertexClient::ID));
    }

    /**
     * embedBody builds the JSON request body for a :predict call.
     *
     * @param list<string>        $inputs
     * @param array<string,mixed> $extra
     */
    public function embedBody(array $inputs, ?EmbedInputType $inputType, int $dimensions, array $extra): string
    {
        $task = match ($inputType) {
            EmbedInputType::Query => 'RETRIEVAL_QUERY',
            EmbedInputType::Document => 'RETRIEVAL_DOCUMENT',
            default => '',
        };
        $instances = [];
        foreach ($inputs as $input) {
            $instances[] = Wire::filter([
                'content' => $input,
                'task_type' => $task === '' ? null : $task,
            ]);
        }

        return Wire::encode([
            'instances' => $instances,
            'parameters' => Wire::filter([
                'outputDimensionality' => $dimensions > 0 ? $dimensions : null,
                'autoTruncate' => true,
            ]),
        ], $extra);
    }

    /**
     * toResponse maps a decoded generateContent reply onto a Response.
     *
     * @param array<string,mixed> $data
     */
    public function toResponse(array $data): Response
    {
        $usage = Arr::obj($data, 'usageMetadata');
        $model = Arr::str($data, 'modelVersion');
        $candidates = Arr::objects($data, 'candidates');
        if ($candidates === []) {
            return new Response(
                new Message(Role::Assistant),
                self::isBlocked($data) ? FinishReason::ContentFilter : FinishReason::Other,
                $usage === [] ? new Usage() : self::usage($usage),
                Arr::str($data, 'responseId'),
                $model === '' ? $this->model : $model,
            );
        }
        $candidate = $candidates[0];
        $parts = [];
        $calls = 0;
        foreach (Arr::objects(Arr::obj($candidate, 'content'), 'parts') as $part) {
            foreach (self::toParts($part, $calls) as $mapped) {
                $parts[] = $mapped;
            }
        }

        return new Response(
            new Message(Role::Assistant, $parts),
            self::finishReason(Arr::str($candidate, 'finishReason'), $calls > 0),
            $usage === [] ? new Usage() : self::usage($usage),
            Arr::str($data, 'responseId'),
            $model === '' ? $this->model : $model,
        );
    }

    /**
     * toParts maps one wire part, splitting a thought signature that rides on
     * a text or function-call part into its own reasoning part.
     *
     * @param array<string,mixed> $part
     *
     * @return list<Part>
     */
    public static function toParts(array $part, int &$calls): array
    {
        $signature = Arr::str($part, 'thoughtSignature');
        $text = Arr::str($part, 'text');
        if (Arr::bool($part, 'thought')) {
            return [new ReasoningPart($text, $signature)];
        }
        $call = Arr::obj($part, 'functionCall');
        if ($call !== []) {
            ++$calls;
            $out = $signature === '' ? [] : [new ReasoningPart(signature: $signature)];
            $out[] = new ToolCall(
                self::callId($calls),
                Arr::str($call, 'name'),
                self::rawArguments($call['args'] ?? null),
            );

            return $out;
        }
        if ($text === '') {
            return [];
        }
        $out = $signature === '' ? [] : [new ReasoningPart(signature: $signature)];
        $out[] = new TextPart($text);

        return $out;
    }

    /** @param array<string,mixed> $data */
    public static function isBlocked(array $data): bool
    {
        return Arr::str(Arr::obj($data, 'promptFeedback'), 'blockReason') !== '';
    }

    /** callId synthesises an ID because Gemini function calls carry none. */
    public static function callId(int $n): string
    {
        return 'call_' . $n;
    }

    public static function rawArguments(mixed $args): string
    {
        if ($args === null || $args === [] || $args === '') {
            return '{}';
        }

        return is_string($args) ? $args : Json::encode($args);
    }

    /** @param array<string,mixed> $usage */
    public static function usage(array $usage): Usage
    {
        $input = Arr::int($usage, 'promptTokenCount');
        $thoughts = Arr::int($usage, 'thoughtsTokenCount');
        $output = Arr::int($usage, 'candidatesTokenCount') + $thoughts;
        $total = Arr::int($usage, 'totalTokenCount');

        return new Usage(
            inputTokens: $input,
            outputTokens: $output,
            totalTokens: $total === 0 ? $input + $output : $total,
            cachedInputTokens: Arr::int($usage, 'cachedContentTokenCount'),
            reasoningTokens: $thoughts,
        );
    }

    public static function finishReason(string $reason, bool $hasTools): FinishReason
    {
        return match ($reason) {
            'STOP' => $hasTools ? FinishReason::ToolCalls : FinishReason::Stop,
            'MAX_TOKENS' => FinishReason::Length,
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY' =>
                FinishReason::ContentFilter,
            default => FinishReason::Other,
        };
    }

    /**
     * contents hoists system text and merges adjacent same-role turns.
     *
     * @param list<Message> $messages
     *
     * @return array{array<string,mixed>|null,list<array<string,mixed>>}
     */
    private function contents(array $messages): array
    {
        $system = [];
        $turns = [];
        foreach ($messages as $message) {
            if ($message->role === Role::System) {
                $system[] = $message->text();
                continue;
            }
            $role = $message->role === Role::Assistant ? self::ROLE_MODEL : self::ROLE_USER;
            $parts = $this->parts($message->parts);
            if ($parts === []) {
                continue;
            }
            $last = count($turns) - 1;
            if ($last >= 0 && $turns[$last]['role'] === $role) {
                /** @var list<array<string,mixed>> $existing */
                $existing = $turns[$last]['parts'];
                $turns[$last] = ['role' => $role, 'parts' => array_merge($existing, $parts)];
                continue;
            }
            $turns[] = ['role' => $role, 'parts' => $parts];
        }
        $instruction = $system === [] ? null : ['parts' => [['text' => implode("\n", $system)]]];

        return [$instruction, array_values($turns)];
    }

    /**
     * @param list<Part> $parts
     *
     * @return list<array<string,mixed>>
     */
    private function parts(array $parts): array
    {
        $out = [];
        $pending = '';
        foreach ($parts as $part) {
            // A text-less ReasoningPart is a bare thoughtSignature that rides on the next part.
            if ($part instanceof ReasoningPart && $part->text === '') {
                $pending = $part->signature;
                continue;
            }
            $wire = $this->part($part);
            if ($wire === null) {
                continue;
            }
            if (($wire['thoughtSignature'] ?? '') === '' && $pending !== '') {
                $wire['thoughtSignature'] = $pending;
            }
            $pending = '';
            $out[] = $wire;
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function part(Part $part): ?array
    {
        if ($part instanceof TextPart) {
            return $part->text === '' ? null : ['text' => $part->text];
        }
        if ($part instanceof ImagePart) {
            return self::media($part->data, $part->mime, $part->url);
        }
        if ($part instanceof AudioPart) {
            return self::media($part->data, $part->mime, '');
        }
        if ($part instanceof FilePart) {
            return self::media($part->data, $part->mime, $part->url);
        }
        if ($part instanceof ReasoningPart) {
            return $part->signature === ''
                ? null
                : ['text' => $part->text, 'thought' => true, 'thoughtSignature' => $part->signature];
        }
        if ($part instanceof ToolCall) {
            if ($part->arguments !== '' && !json_validate($part->arguments)) {
                throw new InvalidRequestException(sprintf(
                    '%s: tool call "%s": arguments are not valid JSON',
                    VertexClient::ID,
                    $part->name,
                ));
            }

            return ['functionCall' => [
                'name' => $part->name,
                'args' => Json::decode(self::rawArguments($part->arguments), 'decode tool arguments'),
            ]];
        }
        if ($part instanceof ToolResult) {
            return self::functionResponse($part);
        }

        throw UnsupportedException::forProvider(VertexClient::ID, $part::class);
    }

    /** @return array<string,mixed> */
    private static function media(string $data, string $mime, string $uri): array
    {
        if ($uri !== '') {
            return ['fileData' => Wire::filter([
                'mimeType' => $mime === '' ? (self::mimeFromUrl($uri) ?: null) : $mime,
                'fileUri' => $uri,
            ])];
        }

        return ['inlineData' => [
            'mimeType' => Wire::mimeOr($mime, $data),
            'data' => base64_encode($data),
        ]];
    }

    private static function mimeFromUrl(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $extension = strtolower(pathinfo(is_string($path) ? $path : $uri, PATHINFO_EXTENSION));

        return self::MIME_BY_EXTENSION[$extension] ?? '';
    }

    /** @return array<string,mixed> */
    private static function functionResponse(ToolResult $result): array
    {
        if ($result->name === '') {
            throw new InvalidRequestException(VertexClient::ID . ': tool results need a name');
        }
        foreach ($result->content as $part) {
            if (!$part instanceof TextPart) {
                throw UnsupportedException::forProvider(
                    VertexClient::ID,
                    $part::class . ' in tool result',
                );
            }
        }
        $text = $result->toText();
        $value = json_validate($text) ? Json::decode($text, 'decode tool result') : $text;

        return ['functionResponse' => [
            'name' => $result->name,
            'response' => [$result->isError ? 'error' : 'result' => $value],
        ]];
    }

    /** @return list<array<string,mixed>> */
    private function toolDeclarations(Request $request): array
    {
        $out = [];
        foreach ($request->tools as $tool) {
            $out[] = Wire::filter([
                'name' => $tool->name,
                'description' => $tool->description === '' ? null : $tool->description,
                'parameters' => $tool->parameters === [] ? null : $tool->parameters,
            ]);
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function toolConfig(Request $request): ?array
    {
        $choice = $request->toolChoice;
        if ($choice === null) {
            return null;
        }

        return match ($choice->mode) {
            ToolChoiceMode::None => ['functionCallingConfig' => ['mode' => 'NONE']],
            ToolChoiceMode::Required => ['functionCallingConfig' => ['mode' => self::MODE_ANY]],
            ToolChoiceMode::Named => ['functionCallingConfig' => [
                'mode' => self::MODE_ANY,
                'allowedFunctionNames' => [$choice->name],
            ]],
            ToolChoiceMode::Auto => null,
        };
    }

    /** @return array<string,mixed> */
    private static function generationConfig(Request $request): array
    {
        $config = Wire::filter([
            'temperature' => $request->temperature,
            'topP' => $request->topP,
            'maxOutputTokens' => $request->maxTokens > 0 ? $request->maxTokens : null,
            'stopSequences' => $request->stop === [] ? null : $request->stop,
            'seed' => $request->seed,
        ]);
        $format = $request->format;
        if ($format !== null) {
            if ($format->type === ResponseFormat::TYPE_JSON) {
                $config['responseMimeType'] = self::MIME_JSON;
            } elseif ($format->type === ResponseFormat::TYPE_JSON_SCHEMA) {
                if ($format->schema === []) {
                    throw new InvalidRequestException(
                        VertexClient::ID . ': json_schema format requires a schema',
                    );
                }
                $config['responseMimeType'] = self::MIME_JSON;
                $config['responseJsonSchema'] = $format->schema;
            } else {
                throw new InvalidRequestException(sprintf(
                    '%s: unknown response format "%s"',
                    VertexClient::ID,
                    $format->type,
                ));
            }
        }
        if ($request->reasoning !== null) {
            $config['thinkingConfig'] = [
                'includeThoughts' => true,
                // -1 asks for dynamic thinking when no explicit budget is given.
                'thinkingBudget' => $request->reasoning->budgetTokens > 0
                    ? $request->reasoning->budgetTokens
                    : -1,
            ];
        }

        return $config;
    }
}
