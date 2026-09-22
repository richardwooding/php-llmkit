<?php

declare(strict_types=1);

namespace LlmKit;

/**
 * Chunk is one streamed event. Exactly one ChunkKind::Finish ends every
 * successful stream, carrying the finish reason and, when the provider
 * reports it, usage. For ChunkKind::Reasoning, $text mirrors
 * $reasoning->text so simple consumers can print it; $reasoning carries the
 * block index and signatures Stream::collect needs.
 */
final readonly class Chunk
{
    public function __construct(
        public ChunkKind $kind,
        public string $text = '',
        public ?ReasoningDelta $reasoning = null,
        public ?ToolCallDelta $toolCall = null,
        public ?FinishReason $finishReason = null,
        public ?Usage $usage = null,
        public ?string $raw = null,
    ) {}

    /** text builds a text chunk. */
    public static function text(string $text, ?string $raw = null): self
    {
        return new self(ChunkKind::Text, text: $text, raw: $raw);
    }

    /** reasoning builds a reasoning chunk, mirroring the delta's text. */
    public static function reasoning(ReasoningDelta $delta, ?string $raw = null): self
    {
        return new self(ChunkKind::Reasoning, text: $delta->text, reasoning: $delta, raw: $raw);
    }

    /** toolCall builds a tool-call chunk. */
    public static function toolCall(ToolCallDelta $delta, ?string $raw = null): self
    {
        return new self(ChunkKind::ToolCall, toolCall: $delta, raw: $raw);
    }

    /** finish builds the terminal chunk of a stream. */
    public static function finish(FinishReason $reason, ?Usage $usage = null, ?string $raw = null): self
    {
        return new self(ChunkKind::Finish, finishReason: $reason, usage: $usage, raw: $raw);
    }

    /** withoutRaw returns a copy with the provider payload dropped. */
    public function withoutRaw(): self
    {
        return new self(
            $this->kind,
            $this->text,
            $this->reasoning,
            $this->toolCall,
            $this->finishReason,
            $this->usage,
            null,
        );
    }
}
