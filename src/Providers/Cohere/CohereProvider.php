<?php

declare(strict_types=1);

namespace LlmKit\Providers\Cohere;

use LlmKit\Client;
use LlmKit\Config;
use LlmKit\Provider;

/** CohereProvider registers Cohere with a Registry. */
final readonly class CohereProvider implements Provider
{
    public function id(): string
    {
        return CohereClient::ID;
    }

    public function matches(string $bareModel): bool
    {
        return CohereClient::matchesModel($bareModel);
    }

    public function open(string $model, Config $config): Client
    {
        return CohereClient::create($model, $config);
    }
}
