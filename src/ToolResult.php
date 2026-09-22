<?php

declare(strict_types=1);

namespace LlmKit;

/**
 * ToolResult is the outcome of a ToolCall, sent back in a Role::Tool message.
 * Name is required by providers that address tools by name (Gemini).
 */
final readonly class ToolResult implements Part
{
    /** @param list<Part> $content */
    public function __construct(
        public string $callId = '',
        public string $name = '',
        public array $content = [],
        public bool $isError = false,
    ) {}

    /** text builds a ToolResult whose content is a single text part. */
    public static function text(string $callId, string $name, string $text, bool $isError = false): self
    {
        return new self($callId, $name, [new TextPart($text)], $isError);
    }

    /** error builds a failed ToolResult carrying a message for the model. */
    public static function error(string $callId, string $name, string $message): self
    {
        return self::text($callId, $name, $message, true);
    }

    /** toText returns the concatenated text of the result's TextParts. */
    public function toText(): string
    {
        $out = '';
        foreach ($this->content as $part) {
            if ($part instanceof TextPart) {
                $out .= $part->text;
            }
        }

        return $out;
    }
}
