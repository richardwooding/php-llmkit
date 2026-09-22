<?php

declare(strict_types=1);

namespace LlmKit\Catalog;

/** Capabilities lists what a model accepts or produces. */
final readonly class Capabilities
{
    public function __construct(
        public bool $tools = false,
        public bool $vision = false,
        public bool $reasoning = false,
        public bool $jsonSchema = false,
        public bool $promptCache = false,
    ) {}
}
