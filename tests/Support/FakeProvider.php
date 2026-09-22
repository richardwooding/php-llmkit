<?php

declare(strict_types=1);

namespace LlmKit\Tests\Support;

use LlmKit\Client;
use LlmKit\Config;
use LlmKit\Provider;

/** FakeProvider claims model names by prefix and opens FakeClients. */
final readonly class FakeProvider implements Provider
{
    /** @param list<string> $prefixes */
    public function __construct(
        private string $id,
        private array $prefixes = [],
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function matches(string $bareModel): bool
    {
        foreach ($this->prefixes as $prefix) {
            if (str_starts_with(strtolower($bareModel), $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function open(string $model, Config $config): Client
    {
        return new FakeClient($this->id, $model);
    }
}
