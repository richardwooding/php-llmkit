<?php

declare(strict_types=1);

namespace LlmKit;

/**
 * ReasoningDelta is a fragment of a streamed reasoning block. Index is a
 * reasoning-block ordinal, stable for one block within a response and
 * independent of ToolCallDelta::$index. Text accumulates across fragments;
 * signature and encrypted are opaque provider tokens that usually arrive on
 * the fragment that closes the block and must be echoed back on later turns.
 */
final readonly class ReasoningDelta
{
    public function __construct(
        public int $index = 0,
        public string $text = '',
        public string $signature = '',
        public string $encrypted = '',
    ) {}
}
