<?php

declare(strict_types=1);

namespace LlmKit;

/**
 * ReasoningPart carries a model's thinking. Signature and encrypted are opaque
 * provider tokens that must be echoed back on later turns where present.
 */
final readonly class ReasoningPart implements Part
{
    public function __construct(
        public string $text = '',
        public string $signature = '',
        public string $encrypted = '',
    ) {}

    /** isEmpty reports whether the block carries neither text nor tokens. */
    public function isEmpty(): bool
    {
        return $this->text === '' && $this->signature === '' && $this->encrypted === '';
    }
}
