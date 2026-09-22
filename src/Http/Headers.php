<?php

declare(strict_types=1);

namespace LlmKit\Http;

/**
 * Headers normalises and reads HTTP header maps.
 *
 * @internal
 */
final class Headers
{
    /**
     * normalise turns any header map into name => list of values.
     *
     * @param array<array-key,string|array<array-key,string>> $headers
     *
     * @return array<string,list<string>>
     */
    public static function normalise(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[(string) $name] = is_string($value) ? [$value] : array_values($value);
        }

        return $out;
    }

    /**
     * first returns the first value of $name, case-insensitively, or ''.
     *
     * @param array<string,list<string>> $headers
     */
    public static function first(array $headers, string $name): string
    {
        foreach ($headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values[0] ?? '';
            }
        }

        return '';
    }
}
