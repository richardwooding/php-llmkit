<?php

declare(strict_types=1);

namespace LlmKit;

/** EmbedRequest asks for one vector per input. */
final readonly class EmbedRequest
{
    /**
     * @param list<string>                      $inputs
     * @param array<string,mixed>               $extra
     * @param array<string,array<string,mixed>> $providerOptions
     */
    public function __construct(
        public array $inputs,
        public int $dimensions = 0,
        public ?EmbedInputType $inputType = null,
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
