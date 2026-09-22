<?php

declare(strict_types=1);

namespace LlmKit\Providers\Vertex;

use LlmKit\Client;
use LlmKit\Config;
use LlmKit\Provider;

/** VertexProvider registers Vertex AI with a Registry. */
final readonly class VertexProvider implements Provider
{
    public function id(): string
    {
        return VertexClient::ID;
    }

    public function matches(string $bareModel): bool
    {
        return VertexClient::matchesModel($bareModel);
    }

    public function open(string $model, Config $config): Client
    {
        return VertexClient::create($model, $config);
    }
}
