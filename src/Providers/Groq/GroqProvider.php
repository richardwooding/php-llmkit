<?php

declare(strict_types=1);

namespace LlmKit\Providers\Groq;

use LlmKit\Client;
use LlmKit\Config;
use LlmKit\Provider;

/** GroqProvider registers Groq with a Registry. */
final readonly class GroqProvider implements Provider
{
    public function id(): string
    {
        return GroqClient::ID;
    }

    public function matches(string $bareModel): bool
    {
        return false;
    }

    public function open(string $model, Config $config): Client
    {
        return GroqClient::create($model, $config);
    }
}
