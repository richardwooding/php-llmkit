<?php

declare(strict_types=1);

namespace LlmKit;

/**
 * RerankRequest asks for documents ordered by relevance to $query. $topN
 * limits the results when positive.
 */
final readonly class RerankRequest
{
    /**
     * @param list<string>                      $documents
     * @param array<string,mixed>               $extra
     * @param array<string,array<string,mixed>> $providerOptions
     */
    public function __construct(
        public string $query,
        public array $documents,
        public int $topN = 0,
        public array $extra = [],
        public array $providerOptions = [],
    ) {}

    /**
     * providerExtra returns the merged provider-specific overrides for $id.
     *
     * @return array<string,mixed>
     */
    public function providerExtra(string $id): array
    {
        return [...$this->extra, ...($this->providerOptions[$id] ?? [])];
    }
}
