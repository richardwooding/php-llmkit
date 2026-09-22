<?php

declare(strict_types=1);

namespace LlmKit;

use JsonSerializable;
use LlmKit\Exception\InvalidRequestException;
use LlmKit\Internal\Json;

/** Message is one turn in a conversation. */
final readonly class Message implements JsonSerializable
{
    /** @param list<Part> $parts */
    public function __construct(
        public Role $role,
        public array $parts = [],
        public string $name = '',
    ) {}

    /** system builds a system message. */
    public static function system(string $text): self
    {
        return new self(Role::System, [new TextPart($text)]);
    }

    /** user builds a user message. */
    public static function user(Part ...$parts): self
    {
        return new self(Role::User, array_values($parts));
    }

    /** userText builds a user message with a single text part. */
    public static function userText(string $text): self
    {
        return self::user(new TextPart($text));
    }

    /** assistant builds an assistant message. */
    public static function assistant(Part ...$parts): self
    {
        return new self(Role::Assistant, array_values($parts));
    }

    /** assistantText builds an assistant message with a single text part. */
    public static function assistantText(string $text): self
    {
        return self::assistant(new TextPart($text));
    }

    /** tool builds a tool message carrying one or more results. */
    public static function tool(ToolResult ...$results): self
    {
        return new self(Role::Tool, array_values($results));
    }

    /** text returns the concatenated text of the message's TextParts. */
    public function text(): string
    {
        $out = '';
        foreach ($this->parts as $part) {
            if ($part instanceof TextPart) {
                $out .= $part->text;
            }
        }

        return $out;
    }

    /**
     * toolCalls returns the message's ToolCall parts in order.
     *
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        $out = [];
        foreach ($this->parts as $part) {
            if ($part instanceof ToolCall) {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * toolResults returns the message's ToolResult parts in order.
     *
     * @return list<ToolResult>
     */
    public function toolResults(): array
    {
        $out = [];
        foreach ($this->parts as $part) {
            if ($part instanceof ToolResult) {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * jsonSerialize encodes the message with a "type" discriminator per part
     * so it can be persisted and read back with fromArray.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        $out = ['role' => $this->role->value];
        if ($this->name !== '') {
            $out['name'] = $this->name;
        }
        $out['parts'] = self::encodeParts($this->parts);

        return $out;
    }

    /** toJson encodes the message as JSON. */
    public function toJson(): string
    {
        return Json::encode($this);
    }

    /** fromJson decodes the format produced by toJson. */
    public static function fromJson(string $json): self
    {
        return self::fromArray(Json::decodeObject($json, 'decode message'));
    }

    /**
     * fromArray decodes the format produced by jsonSerialize.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $role = Role::tryFrom(is_string($data['role'] ?? null) ? $data['role'] : '');
        if ($role === null) {
            throw new InvalidRequestException('llmkit: message has no valid role');
        }
        $raw = $data['parts'] ?? [];
        if (!is_array($raw)) {
            throw new InvalidRequestException('llmkit: message parts must be a list');
        }
        $name = is_string($data['name'] ?? null) ? $data['name'] : '';

        return new self($role, self::decodeParts($raw), $name);
    }

    /**
     * @param list<Part> $parts
     *
     * @return list<array<string,mixed>>
     */
    private static function encodeParts(array $parts): array
    {
        $out = [];
        foreach ($parts as $part) {
            $out[] = self::encodePart($part);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function encodePart(Part $part): array
    {
        return match (true) {
            $part instanceof TextPart => ['type' => 'text', 'text' => $part->text],
            $part instanceof ImagePart => self::compact([
                'type' => 'image',
                'data' => $part->data === '' ? null : base64_encode($part->data),
                'mime' => $part->mime,
                'url' => $part->url,
                'detail' => $part->detail,
            ]),
            $part instanceof AudioPart => self::compact([
                'type' => 'audio',
                'data' => $part->data === '' ? null : base64_encode($part->data),
                'mime' => $part->mime,
            ]),
            $part instanceof FilePart => self::compact([
                'type' => 'file',
                'data' => $part->data === '' ? null : base64_encode($part->data),
                'mime' => $part->mime,
                'url' => $part->url,
                'name' => $part->name,
            ]),
            $part instanceof ReasoningPart => self::compact([
                'type' => 'reasoning',
                'text' => $part->text,
                'signature' => $part->signature,
                'encrypted' => $part->encrypted,
            ]),
            $part instanceof ToolCall => self::compact([
                'type' => 'tool_call',
                'id' => $part->id,
                'name' => $part->name,
                'arguments' => $part->arguments === '' ? null : $part->arguments,
            ]),
            $part instanceof ToolResult => self::compact([
                'type' => 'tool_result',
                'call_id' => $part->callId,
                'name' => $part->name,
                'content' => self::encodeParts($part->content),
                'is_error' => $part->isError ? true : null,
            ]),
            default => throw new InvalidRequestException(
                'llmkit: cannot encode part of type ' . $part::class,
            ),
        };
    }

    /**
     * @param array<array-key,mixed> $raw
     *
     * @return list<Part>
     */
    private static function decodeParts(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                throw new InvalidRequestException('llmkit: message part must be an object');
            }
            /** @var array<string,mixed> $entry */
            $out[] = self::decodePart($entry);
        }

        return $out;
    }

    /** @param array<string,mixed> $p */
    private static function decodePart(array $p): Part
    {
        $str = static fn(string $key): string => is_string($p[$key] ?? null) ? $p[$key] : '';
        $bin = static fn(string $key): string => is_string($p[$key] ?? null)
            ? (base64_decode($p[$key], true) ?: '')
            : '';

        return match ($str('type')) {
            'text' => new TextPart($str('text')),
            'image' => new ImagePart($bin('data'), $str('mime'), $str('url'), $str('detail')),
            'audio' => new AudioPart($bin('data'), $str('mime')),
            'file' => new FilePart($bin('data'), $str('mime'), $str('url'), $str('name')),
            'reasoning' => new ReasoningPart($str('text'), $str('signature'), $str('encrypted')),
            'tool_call' => new ToolCall($str('id'), $str('name'), self::rawJson($p['arguments'] ?? null)),
            'tool_result' => new ToolResult(
                $str('call_id'),
                $str('name'),
                self::decodeParts(is_array($p['content'] ?? null) ? $p['content'] : []),
                ($p['is_error'] ?? false) === true,
            ),
            default => throw new InvalidRequestException(
                'llmkit: unknown part type "' . $str('type') . '"',
            ),
        };
    }

    private static function rawJson(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return '';
        }

        return Json::encode($value);
    }

    /**
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    private static function compact(array $fields): array
    {
        return array_filter(
            $fields,
            static fn(mixed $v): bool => $v !== null && $v !== '' && $v !== [],
        );
    }
}
