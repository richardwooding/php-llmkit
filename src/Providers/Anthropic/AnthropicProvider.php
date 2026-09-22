<?php

declare(strict_types=1);

namespace LlmKit\Providers\Anthropic;

use LlmKit\Client;
use LlmKit\Config;
use LlmKit\Provider;

/** AnthropicProvider registers Anthropic with a Registry. */
final readonly class AnthropicProvider implements Provider
{
    public function id(): string
    {
        return AnthropicClient::ID;
    }

    public function matches(string $bareModel): bool
    {
        return AnthropicClient::matchesModel($bareModel);
    }

    public function open(string $model, Config $config): Client
    {
        return AnthropicClient::create($model, $config);
    }
}
