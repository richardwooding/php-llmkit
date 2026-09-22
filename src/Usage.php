<?php

declare(strict_types=1);

namespace LlmKit;

use JsonSerializable;

/**
 * Usage reports token consumption. Fields a provider does not report are zero.
 *
 * $inputTokens is the whole prompt on every provider, including the tokens
 * served from cache ($cachedInputTokens) and written to it
 * ($cacheWriteTokens); both are subsets of $inputTokens, so the uncached
 * remainder is inputTokens - cachedInputTokens - cacheWriteTokens.
 * $totalTokens is inputTokens + outputTokens. $reasoningTokens is a subset of
 * $outputTokens.
 */
final readonly class Usage implements JsonSerializable
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $totalTokens = 0,
        public int $cachedInputTokens = 0,
        public int $cacheWriteTokens = 0,
        public int $reasoningTokens = 0,
    ) {}

    /** add returns the field-wise sum of this usage and $other. */
    public function add(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->totalTokens + $other->totalTokens,
            $this->cachedInputTokens + $other->cachedInputTokens,
            $this->cacheWriteTokens + $other->cacheWriteTokens,
            $this->reasoningTokens + $other->reasoningTokens,
        );
    }

    /** @return array<string,int> */
    public function jsonSerialize(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'cached_input_tokens' => $this->cachedInputTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
