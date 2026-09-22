<?php

declare(strict_types=1);

namespace LlmKit\Catalog;

/**
 * Pricing is USD per million tokens. $cacheRead and $cacheWrite price the
 * Usage::$cachedInputTokens and Usage::$cacheWriteTokens subsets of the
 * prompt; providers without a write premium set $cacheWrite equal to $input.
 */
final readonly class Pricing
{
    public function __construct(
        public float $input = 0.0,
        public float $output = 0.0,
        public float $cacheRead = 0.0,
        public float $cacheWrite = 0.0,
    ) {}
}
