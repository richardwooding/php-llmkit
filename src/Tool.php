<?php

declare(strict_types=1);

namespace LlmKit;

use LlmKit\Internal\Json;

/** Tool declares a function the model may call. */
final readonly class Tool
{
    /** @param array<string,mixed> $parameters JSON Schema object describing the arguments */
    public function __construct(
        public string $name,
        public string $description = '',
        public array $parameters = [],
        public bool $strict = false,
    ) {}

    /** fromJsonSchema builds a Tool from a JSON Schema string. */
    public static function fromJsonSchema(
        string $name,
        string $description,
        string $schema,
        bool $strict = false,
    ): self {
        return new self($name, $description, Json::decodeObject($schema, 'decode tool schema'), $strict);
    }
}
