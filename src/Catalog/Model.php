<?php

declare(strict_types=1);

namespace LlmKit\Catalog;

use LlmKit\Usage;

/**
 * Model describes one catalog row. $known is false for the placeholder
 * Catalog::lookup returns when nothing matches.
 */
final readonly class Model
{
    /** @param list<string> $aliases */
    public function __construct(
        public string $id,
        public string $provider = '',
        public string $family = '',
        public string $displayName = '',
        public int $contextWindow = 0,
        public int $maxOutput = 0,
        public Pricing $pricing = new Pricing(),
        public Capabilities $capabilities = new Capabilities(),
        public array $aliases = [],
        public bool $known = false,
    ) {}

    /** unknown builds the placeholder for a model the catalog has no data for. */
    public static function unknown(string $id): self
    {
        return new self($id);
    }

    /** asKnown returns a copy marked as known, which is what Catalog::register stores. */
    public function asKnown(): self
    {
        return new self(
            $this->id,
            $this->provider,
            $this->family,
            $this->displayName,
            $this->contextWindow,
            $this->maxOutput,
            $this->pricing,
            $this->capabilities,
            $this->aliases,
            true,
        );
    }

    /**
     * cost prices $usage at this model's list rates: uncached input, cache
     * reads and cache writes at their own rates, plus output. It follows the
     * Usage convention that cached and cache-write tokens are subsets of the
     * input total. An unknown model costs zero.
     */
    public function cost(Usage $usage): float
    {
        $uncached = max($usage->inputTokens - $usage->cachedInputTokens - $usage->cacheWriteTokens, 0);

        return ($uncached * $this->pricing->input
            + $usage->cachedInputTokens * $this->pricing->cacheRead
            + $usage->cacheWriteTokens * $this->pricing->cacheWrite
            + $usage->outputTokens * $this->pricing->output) / 1e6;
    }
}
