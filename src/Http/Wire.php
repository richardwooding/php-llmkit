<?php

declare(strict_types=1);

namespace LlmKit\Http;

use LlmKit\Internal\Json;

/**
 * Wire builds provider request bodies.
 *
 * @internal
 */
final class Wire
{
    /**
     * encode serialises $body as JSON and overlays $extra onto its top-level
     * keys, which is how Request::$extra and provider options reach the wire.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed> $extra
     */
    public static function encode(array $body, array $extra = []): string
    {
        return Json::encode($extra === [] ? $body : [...$body, ...$extra]);
    }

    /**
     * filter drops null values, so optional wire fields can be written
     * unconditionally.
     *
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    public static function filter(array $fields): array
    {
        return array_filter($fields, static fn(mixed $v): bool => $v !== null);
    }

    /** dataUri encodes $data as a base64 data: URI. */
    public static function dataUri(string $mime, string $data): string
    {
        return 'data:' . self::mimeOr($mime, $data) . ';base64,' . base64_encode($data);
    }

    /** mimeOr returns $mime, or sniffs one from $data when it is empty. */
    public static function mimeOr(string $mime, string $data): string
    {
        if ($mime !== '') {
            return $mime;
        }
        if ($data !== '' && function_exists('finfo_buffer')) {
            $info = finfo_open(FILEINFO_MIME_TYPE);
            if ($info !== false) {
                $sniffed = finfo_buffer($info, $data);
                finfo_close($info);
                if (is_string($sniffed) && $sniffed !== '') {
                    return $sniffed;
                }
            }
        }

        return 'application/octet-stream';
    }
}
