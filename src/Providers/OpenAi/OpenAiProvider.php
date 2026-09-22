<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenAi;

use LlmKit\Client;
use LlmKit\Config;
use LlmKit\Provider;

/** OpenAiProvider registers OpenAI with a Registry. */
final readonly class OpenAiProvider implements Provider
{
    public function id(): string
    {
        return OpenAiClient::ID;
    }

    public function matches(string $bareModel): bool
    {
        return OpenAiClient::matchesModel($bareModel);
    }

    public function open(string $model, Config $config): Client
    {
        return OpenAiClient::create($model, $config);
    }
}
