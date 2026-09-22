<?php

declare(strict_types=1);

namespace LlmKit\Internal;

/**
 * Arr reads values out of decoded JSON with a known type.
 *
 * @internal
 */
final class Arr
{
    /** @param array<string,mixed> $data */
    public static function str(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return match (true) {
            is_string($value) => $value,
            is_int($value) || is_float($value) => (string) $value,
            default => $default,
        };
    }

    /** @param array<string,mixed> $data */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return match (true) {
            is_int($value) => $value,
            is_float($value) => (int) $value,
            is_string($value) && is_numeric($value) => (int) $value,
            default => $default,
        };
    }

    /** @param array<string,mixed> $data */
    public static function float(array $data, string $key, float $default = 0.0): float
    {
        $value = $data[$key] ?? null;

        return match (true) {
            is_int($value) || is_float($value) => (float) $value,
            is_string($value) && is_numeric($value) => (float) $value,
            default => $default,
        };
    }

    /** @param array<string,mixed> $data */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * obj returns a nested JSON object, or an empty array.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public static function obj(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        /** @var array<string,mixed> $value */
        return $value;
    }

    /**
     * objects returns a nested JSON array of objects, skipping anything else.
     *
     * @param array<string,mixed> $data
     *
     * @return list<array<string,mixed>>
     */
    public static function objects(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                /** @var array<string,mixed> $entry */
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * strings returns a nested JSON array of strings.
     *
     * @param array<string,mixed> $data
     *
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * floats returns a nested JSON array of numbers.
     *
     * @param array<string,mixed> $data
     *
     * @return list<float>
     */
    public static function floats(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_int($entry) || is_float($entry)) {
                $out[] = (float) $entry;
            }
        }

        return $out;
    }

    /**
     * rawJson returns $key re-encoded as JSON, or '' when absent.
     *
     * @param array<string,mixed> $data
     */
    public static function rawJson(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return match (true) {
            $value === null => '',
            is_string($value) => $value,
            default => Json::encode($value),
        };
    }
}
