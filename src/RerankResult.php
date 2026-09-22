<?php

declare(strict_types=1);

namespace LlmKit;

use JsonSerializable;

/** RerankResult is one scored document; $index refers to RerankRequest::$documents. */
final readonly class RerankResult implements JsonSerializable
{
    public function __construct(public int $index, public float $score) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return ['index' => $this->index, 'score' => $this->score];
    }
}
