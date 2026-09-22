<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenRouter;

use LlmKit\Client;
use LlmKit\Config;
use LlmKit\Provider;

/** OpenRouterProvider registers OpenRouter with a Registry. */
final readonly class OpenRouterProvider implements Provider
{
    public function id(): string
    {
        return OpenRouterClient::ID;
    }

    public function matches(string $bareModel): bool
    {
        return false;
    }

    public function open(string $model, Config $config): Client
    {
        return OpenRouterClient::create($model, $config);
    }
}
