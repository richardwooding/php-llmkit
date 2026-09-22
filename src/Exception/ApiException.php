<?php

declare(strict_types=1);

namespace LlmKit\Exception;

use RuntimeException;

/**
 * ApiException is a non-success response from a provider. create returns
 * RateLimitedException or ContextLengthExceededException when the status,
 * code or message identify one of those conditions, so both can be caught
 * across vendors.
 *
 * $errorCode is the provider's own error code (Exception::$code is taken);
 * $detail is the provider's message (Exception::$message carries the
 * formatted one).
 */
class ApiException extends RuntimeException implements LlmKitException
{
    /** @param float|null $retryAfter seconds the provider asked us to wait */
    final public function __construct(
        public readonly string $provider,
        public readonly int $status = 0,
        public readonly string $errorCode = '',
        public readonly string $type = '',
        public readonly string $detail = '',
        public readonly ?float $retryAfter = null,
        public readonly string $body = '',
    ) {
        parent::__construct(self::format($provider, $status, $errorCode, $detail));
    }

    /** create builds the most specific ApiException for these fields. */
    public static function create(
        string $provider,
        int $status = 0,
        string $errorCode = '',
        string $type = '',
        string $detail = '',
        ?float $retryAfter = null,
        string $body = '',
    ): self {
        $class = match (true) {
            self::looksRateLimited($status, $errorCode) => RateLimitedException::class,
            self::looksContextLength($errorCode, $detail) => ContextLengthExceededException::class,
            default => self::class,
        };

        return new $class($provider, $status, $errorCode, $type, $detail, $retryAfter, $body);
    }

    /** isRateLimited reports whether the provider refused for rate reasons. */
    public function isRateLimited(): bool
    {
        return self::looksRateLimited($this->status, $this->errorCode);
    }

    /** isContextLength reports whether the prompt exceeded the context window. */
    public function isContextLength(): bool
    {
        return self::looksContextLength($this->errorCode, $this->detail);
    }

    private static function format(string $provider, int $status, string $code, string $detail): string
    {
        $out = $provider . ':';
        if ($status !== 0) {
            $out .= ' HTTP ' . $status;
        }
        if ($code !== '') {
            $out .= ' [' . $code . ']';
        }
        if ($detail !== '') {
            return $out . ': ' . $detail;
        }

        return $status === 0 ? $out . ' request failed' : $out;
    }

    private static function looksRateLimited(int $status, string $code): bool
    {
        return $status === 429 || str_contains(strtolower($code), 'rate_limit');
    }

    private static function looksContextLength(string $code, string $detail): bool
    {
        $code = strtolower($code);
        if (str_contains($code, 'context_length') || str_contains($code, 'context_window')) {
            return true;
        }
        $message = strtolower($detail);
        foreach ([
            'context length',
            'context window',
            'prompt is too long',
            'too many tokens',
            'maximum context',
            'exceeds the maximum number of tokens',
            'input token count',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
