<?php

declare(strict_types=1);

namespace LlmKit;

use LlmKit\Internal\Json;

/** ResponseFormat asks for JSON output, optionally constrained by a schema. */
final readonly class ResponseFormat
{
    public const string TYPE_JSON = 'json';
    public const string TYPE_JSON_SCHEMA = 'json_schema';

    /** @param array<string,mixed> $schema */
    public function __construct(
        public string $type = self::TYPE_JSON,
        public string $name = '',
        public array $schema = [],
        public bool $strict = false,
    ) {}

    /** json asks for any JSON object. */
    public static function json(): self
    {
        return new self(self::TYPE_JSON);
    }

    /**
     * jsonSchema asks for JSON matching $schema.
     *
     * @param array<string,mixed> $schema
     */
    public static function jsonSchema(string $name, array $schema, bool $strict = true): self
    {
        return new self(self::TYPE_JSON_SCHEMA, $name, $schema, $strict);
    }

    /** fromJsonSchema is jsonSchema with the schema as a JSON string. */
    public static function fromJsonSchema(string $name, string $schema, bool $strict = true): self
    {
        return self::jsonSchema($name, Json::decodeObject($schema, 'decode response schema'), $strict);
    }
}
