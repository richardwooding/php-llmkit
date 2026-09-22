<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenAiCompat;

use LlmKit\Client;
use LlmKit\Config;
use LlmKit\Provider;

/**
 * CompatProvider registers one OpenAI-compatible endpoint with a Registry, so
 * "<id>/<model>" resolves to it.
 */
final readonly class CompatProvider implements Provider
{
    public function __construct(private EndpointConfig $config) {}

    public function id(): string
    {
        return $this->config->id;
    }

    public function matches(string $bareModel): bool
    {
        return $this->config->matches($bareModel);
    }

    public function open(string $model, Config $config): Client
    {
        return CompatClient::create($model, $this->config, $config);
    }
}
