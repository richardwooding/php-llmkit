<?php

declare(strict_types=1);

namespace LlmKit\Providers\XAi;

use LlmKit\Client;
use LlmKit\Config;
use LlmKit\Provider;

/** XAiProvider registers x.ai with a Registry. */
final readonly class XAiProvider implements Provider
{
    public function id(): string
    {
        return XAiClient::ID;
    }

    public function matches(string $bareModel): bool
    {
        return XAiClient::endpoint()->matches($bareModel);
    }

    public function open(string $model, Config $config): Client
    {
        return XAiClient::create($model, $config);
    }
}
