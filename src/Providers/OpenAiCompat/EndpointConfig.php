<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenAiCompat;

use Closure;

/** EndpointConfig describes one OpenAI-compatible endpoint. */
final readonly class EndpointConfig
{
    /**
     * @param string                          $apiKeyEnv   environment variable holding the key
     * @param bool                            $keyOptional whether the endpoint works unauthenticated
     * @param array<string,string>            $headers     sent unless the caller set the same header
     * @param (Closure(string): bool)|null   $match       claims bare model names; null never matches
     */
    public function __construct(
        public string $id,
        public string $baseUrl,
        public string $apiKeyEnv = '',
        public bool $keyOptional = false,
        public array $headers = [],
        public ?Closure $match = null,
        public Quirks $quirks = new Quirks(),
    ) {}

    /** prefixMatcher builds a matcher accepting models starting with any prefix. */
    public static function prefixMatcher(string ...$prefixes): Closure
    {
        return static function (string $model) use ($prefixes): bool {
            $model = strtolower($model);
            foreach ($prefixes as $prefix) {
                if (str_starts_with($model, $prefix)) {
                    return true;
                }
            }

            return false;
        };
    }

    /** matches reports whether a bare model name belongs to this endpoint. */
    public function matches(string $model): bool
    {
        return $this->match !== null && ($this->match)($model);
    }
}
