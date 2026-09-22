<?php

declare(strict_types=1);

namespace LlmKit\Providers\HuggingFace;

use LlmKit\Client;
use LlmKit\Config;
use LlmKit\Provider;

/** HuggingFaceProvider registers the Hugging Face router with a Registry. */
final readonly class HuggingFaceProvider implements Provider
{
    public function id(): string
    {
        return HuggingFaceClient::ID;
    }

    public function matches(string $bareModel): bool
    {
        return HuggingFaceClient::endpoint()->matches($bareModel);
    }

    public function open(string $model, Config $config): Client
    {
        return HuggingFaceClient::create($model, $config);
    }
}
