<?php

declare(strict_types=1);

namespace LlmKit;

use LlmKit\Exception\InvalidRequestException;
use LlmKit\Exception\UnknownProviderException;
use LlmKit\Exception\UnsupportedException;

/**
 * Registry resolves model names to providers. Registration order is match
 * priority for bare names.
 */
final class Registry
{
    /**
     * DEFAULT_PROVIDER_ENV names the environment variable consulted for the
     * fallback provider when a bare model name matches nothing.
     */
    public const string DEFAULT_PROVIDER_ENV = 'LLMKIT_DEFAULT_PROVIDER';

    /** @var list<Provider> */
    private array $order = [];

    /** @var array<string,Provider> */
    private array $byId = [];

    private string $fallback = '';

    public function __construct(Provider ...$providers)
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    /**
     * register adds a provider under its ID and any aliases, replacing an
     * earlier provider with the same ID in place.
     */
    public function register(Provider $provider, string ...$aliases): void
    {
        $id = strtolower($provider->id());
        if (isset($this->byId[$id])) {
            foreach ($this->order as $i => $existing) {
                if (strtolower($existing->id()) === $id) {
                    $this->order[$i] = $provider;
                }
            }
        } else {
            $this->order[] = $provider;
        }
        $this->byId[$id] = $provider;
        foreach ($aliases as $alias) {
            $this->byId[strtolower($alias)] = $provider;
        }
    }

    /**
     * setFallback names the provider used for bare model names nothing
     * claims. It takes precedence over LLMKIT_DEFAULT_PROVIDER.
     */
    public function setFallback(string $id): void
    {
        $this->fallback = strtolower($id);
    }

    /**
     * providers returns the registered providers in priority order.
     *
     * @return list<Provider>
     */
    public function providers(): array
    {
        return $this->order;
    }

    /** lookup returns the provider registered under $id or an alias. */
    public function lookup(string $id): ?Provider
    {
        return $this->byId[strtolower($id)] ?? null;
    }

    /**
     * parseModel splits "<provider>/<model>" or resolves a bare "<model>" to
     * the provider that claims it, then the fallback.
     *
     * @return array{Provider,string}
     */
    public function parseModel(string $model): array
    {
        $model = trim($model);
        if ($model === '') {
            throw new InvalidRequestException('llmkit: empty model name');
        }
        $slash = strpos($model, '/');
        if ($slash !== false && $slash < strlen($model) - 1) {
            $provider = $this->lookup(substr($model, 0, $slash));
            if ($provider !== null) {
                return [$provider, substr($model, $slash + 1)];
            }
        }
        foreach ($this->order as $provider) {
            if ($provider->matches($model)) {
                return [$provider, $model];
            }
        }
        $fallback = $this->fallback;
        if ($fallback === '') {
            $env = getenv(self::DEFAULT_PROVIDER_ENV);
            $fallback = is_string($env) ? strtolower($env) : '';
        }
        if ($fallback === '') {
            $fallback = 'ollama';
        }
        $provider = $this->lookup($fallback);
        if ($provider === null) {
            throw new UnknownProviderException(sprintf(
                'llmkit: no provider claims "%s" and fallback "%s" is not registered',
                $model,
                $fallback,
            ));
        }

        return [$provider, $model];
    }

    /** client resolves $model and opens a client for it. */
    public function client(string $model, ?Config $config = null): Client
    {
        [$provider, $name] = $this->parseModel($model);

        return $provider->open($name, $config ?? new Config());
    }

    /**
     * open resolves $model and checks the client against $capability, one of
     * the Chatter, Streamer, Embedder, Reranker, MultimodalEmbedder or
     * TokenCounter interfaces.
     *
     * @template T of Client
     *
     * @param class-string<T> $capability
     *
     * @return T
     */
    public function open(string $capability, string $model, ?Config $config = null): Client
    {
        return self::cast($capability, $this->client($model, $config));
    }

    /**
     * cast checks $client against $capability, failing with
     * UnsupportedException when the provider lacks it.
     *
     * @template T of Client
     *
     * @param class-string<T> $capability
     *
     * @return T
     */
    public static function cast(string $capability, Client $client): Client
    {
        if ($client instanceof $capability) {
            return $client;
        }

        throw new UnsupportedException(sprintf(
            '%s/%s does not implement %s',
            $client->provider(),
            $client->model(),
            $capability,
        ));
    }
}
