<?php

declare(strict_types=1);

namespace LlmKit\Exception;

use RuntimeException;

/**
 * UnsupportedException is thrown before any network call when a provider
 * cannot serve part of a request, or lacks a requested capability.
 */
final class UnsupportedException extends RuntimeException implements LlmKitException
{
    /** forProvider names the provider and the feature it does not support. */
    public static function forProvider(string $provider, string $what): self
    {
        return new self($provider . ': ' . $what . ': unsupported');
    }
}
