<?php

declare(strict_types=1);

namespace LlmKit\Http;

use LlmKit\Exception\ApiException;
use LlmKit\Internal\Json;

/**
 * ErrorParser decodes the error envelopes the supported providers use into an
 * ApiException. Unknown shapes fall back to the raw body as the message.
 *
 * @internal
 */
final class ErrorParser
{
    private const int MAX_MESSAGE = 512;

    /** @param array<string,list<string>> $headers */
    public static function parse(string $provider, int $status, array $headers, string $body): ApiException
    {
        $fields = self::fromBody($body);
        $message = $fields['message'];
        if ($message === '') {
            $message = substr(trim($body), 0, self::MAX_MESSAGE);
        }

        return ApiException::create(
            provider: $provider,
            status: $status,
            errorCode: $fields['code'],
            type: $fields['type'],
            detail: $message,
            retryAfter: self::retryAfter(Headers::first($headers, 'Retry-After')),
            body: $body,
        );
    }

    /** @return array{message:string,code:string,type:string} */
    private static function fromBody(string $body): array
    {
        $out = ['message' => '', 'code' => '', 'type' => ''];
        $env = Json::tryDecodeObject($body);
        if ($env === null) {
            return $out;
        }
        $out['type'] = is_string($env['type'] ?? null) ? $env['type'] : '';
        $out['code'] = self::scalarString($env['code'] ?? null);
        if (is_string($env['message'] ?? null) && $env['message'] !== '') {
            $out['message'] = $env['message'];
        }
        if (is_string($env['detail'] ?? null)) {
            $out['message'] = $env['detail'];
        }
        $error = $env['error'] ?? null;
        if (is_string($error)) {
            $out['message'] = $error;

            return $out;
        }
        if (!is_array($error)) {
            return $out;
        }
        if (is_string($error['message'] ?? null) && $error['message'] !== '') {
            $out['message'] = $error['message'];
        }
        if (is_string($error['type'] ?? null) && $error['type'] !== '') {
            $out['type'] = $error['type'];
        }
        $code = self::scalarString($error['code'] ?? null);
        if ($code !== '') {
            $out['code'] = $code;
        } elseif (is_string($error['status'] ?? null) && $error['status'] !== '') {
            $out['code'] = $error['status'];
        }

        return $out;
    }

    private static function scalarString(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) || is_float($value) => (string) $value,
            default => '',
        };
    }

    private static function retryAfter(string $value): ?float
    {
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $at = strtotime($value);
        if ($at === false) {
            return null;
        }
        $delay = (float) $at - (float) time();

        return $delay > 0 ? $delay : null;
    }
}
