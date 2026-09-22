<?php

declare(strict_types=1);

namespace LlmKit\Providers\Voyage;

use LlmKit\Client;
use LlmKit\Config;
use LlmKit\Provider;

/** VoyageProvider registers Voyage AI with a Registry. */
final readonly class VoyageProvider implements Provider
{
    public function id(): string
    {
        return VoyageClient::ID;
    }

    public function matches(string $bareModel): bool
    {
        return VoyageClient::matchesModel($bareModel);
    }

    public function open(string $model, Config $config): Client
    {
        return VoyageClient::create($model, $config);
    }
}
