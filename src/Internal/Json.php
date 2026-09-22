<?php

declare(strict_types=1);

namespace LlmKit\Internal;

use JsonException;
use LlmKit\Exception\TransportException;

/**
 * Json wraps json_encode/json_decode with exceptions and array shapes.
 *
 * @internal
 */
final class Json
{
    /** encode serialises $value as compact JSON. */
    public static function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new TransportException('llmkit: encode JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /** encodePretty serialises $value as indented JSON. */
    public static function encodePretty(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            );
        } catch (JsonException $e) {
            throw new TransportException('llmkit: encode JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /** decode parses JSON into PHP values, using arrays for objects. */
    public static function decode(string $json, string $what = 'decode JSON'): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TransportException('llmkit: ' . $what . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * decodeObject parses a JSON object into a string-keyed array.
     *
     * @return array<string,mixed>
     */
    public static function decodeObject(string $json, string $what = 'decode JSON'): array
    {
        $out = self::decode($json, $what);
        if (!is_array($out)) {
            throw new TransportException('llmkit: ' . $what . ': expected a JSON object');
        }

        /** @var array<string,mixed> $out */
        return $out;
    }

    /**
     * tryDecodeObject parses a JSON object, returning null instead of throwing.
     *
     * @return array<string,mixed>|null
     */
    public static function tryDecodeObject(string $json): ?array
    {
        /** @var mixed $out */
        $out = json_decode($json, true);
        if (!is_array($out)) {
            return null;
        }

        /** @var array<string,mixed> $out */
        return $out;
    }
}
