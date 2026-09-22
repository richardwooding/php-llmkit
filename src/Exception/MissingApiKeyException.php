<?php

declare(strict_types=1);

namespace LlmKit\Exception;

use RuntimeException;

/** MissingApiKeyException is thrown when no credential is configured. */
final class MissingApiKeyException extends RuntimeException implements LlmKitException
{
    /** forEnv reports which environment variable would supply the key. */
    public static function forEnv(string $provider, string $env): self
    {
        return new self($provider . ': set ' . $env . ' or configure an API key');
    }
}
