<?php

declare(strict_types=1);

namespace LlmKit;

/**
 * CacheConfig asks a provider with explicit prompt caching (Anthropic) to
 * mark cache breakpoints: after the system prompt, after the tool definitions
 * and after the last $turns user-role messages. Providers with automatic
 * caching ignore it. TTL is "5m" (default) or "1h".
 */
final readonly class CacheConfig
{
    public const string TTL_5M = '5m';
    public const string TTL_1H = '1h';

    public function __construct(
        public bool $system = false,
        public bool $tools = false,
        public int $turns = 0,
        public string $ttl = self::TTL_5M,
    ) {}
}
