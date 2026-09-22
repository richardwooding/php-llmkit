<?php

declare(strict_types=1);

namespace LlmKit;

use JsonSerializable;

/** EmbedResponse holds one vector per input, in input order. */
final readonly class EmbedResponse implements JsonSerializable
{
    /** @param list<list<float>> $embeddings */
    public function __construct(
        public array $embeddings,
        public string $model = '',
        public Usage $usage = new Usage(),
        public ?string $raw = null,
    ) {}

    /** withRaw returns a copy carrying the provider's response body. */
    public function withRaw(?string $raw): self
    {
        return new self($this->embeddings, $this->model, $this->usage, $raw);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'model' => $this->model,
            'embeddings' => $this->embeddings,
            'usage' => $this->usage,
        ];
    }
}
