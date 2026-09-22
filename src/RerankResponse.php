<?php

declare(strict_types=1);

namespace LlmKit;

use JsonSerializable;

/** RerankResponse lists results best first. */
final readonly class RerankResponse implements JsonSerializable
{
    /** @param list<RerankResult> $results */
    public function __construct(
        public array $results,
        public string $model = '',
        public Usage $usage = new Usage(),
        public ?string $raw = null,
    ) {}

    /** withRaw returns a copy carrying the provider's response body. */
    public function withRaw(?string $raw): self
    {
        return new self($this->results, $this->model, $this->usage, $raw);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'model' => $this->model,
            'results' => $this->results,
            'usage' => $this->usage,
        ];
    }
}
