<?php

declare(strict_types=1);

namespace LlmKit\Providers\Ollama;

use LlmKit\Client;
use LlmKit\Config;
use LlmKit\Provider;

/** OllamaProvider registers Ollama with a Registry. */
final readonly class OllamaProvider implements Provider
{
    public function id(): string
    {
        return OllamaClient::ID;
    }

    public function matches(string $bareModel): bool
    {
        return OllamaClient::matchesModel($bareModel);
    }

    public function open(string $model, Config $config): Client
    {
        return OllamaClient::create($model, $config);
    }
}
