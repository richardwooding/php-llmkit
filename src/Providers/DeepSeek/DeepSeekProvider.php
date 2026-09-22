<?php

declare(strict_types=1);

namespace LlmKit\Providers\DeepSeek;

use LlmKit\Client;
use LlmKit\Config;
use LlmKit\Provider;

/** DeepSeekProvider registers DeepSeek with a Registry. */
final readonly class DeepSeekProvider implements Provider
{
    public function id(): string
    {
        return DeepSeekClient::ID;
    }

    public function matches(string $bareModel): bool
    {
        return DeepSeekClient::endpoint()->matches($bareModel);
    }

    public function open(string $model, Config $config): Client
    {
        return DeepSeekClient::create($model, $config);
    }
}
