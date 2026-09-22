<?php

declare(strict_types=1);

namespace LlmKit;

use LlmKit\Http\Transport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Config carries connection settings for Provider::open. It is immutable:
 * every with* method returns a copy.
 */
final readonly class Config
{
    /**
     * @param array<string,list<string>> $headers sent with every request
     * @param float|null                 $timeout seconds, bounding each call including a whole stream
     * @param array<string,mixed>        $values  provider-specific settings, keyed by the provider's constants
     */
    public function __construct(
        public ?string $apiKey = null,
        public ?string $baseUrl = null,
        public array $headers = [],
        public ?float $timeout = null,
        public ?Transport $transport = null,
        public ?ClientInterface $httpClient = null,
        public ?RequestFactoryInterface $requestFactory = null,
        public ?StreamFactoryInterface $streamFactory = null,
        public array $values = [],
    ) {}

    /** withApiKey overrides the provider's environment-sourced key. */
    public function withApiKey(string $apiKey): self
    {
        return $this->with(apiKey: $apiKey);
    }

    /** withBaseUrl points the client at a different endpoint. */
    public function withBaseUrl(string $baseUrl): self
    {
        return $this->with(baseUrl: $baseUrl);
    }

    /** withHeader adds a header to every request. */
    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $key = $this->headerKey($name) ?? $name;
        $headers[$key][] = $value;

        return $this->with(headers: $headers);
    }

    /** withTimeout bounds each call, including a whole stream, in seconds. */
    public function withTimeout(float $seconds): self
    {
        return $this->with(timeout: $seconds);
    }

    /** withTransport replaces the HTTP transport entirely. */
    public function withTransport(Transport $transport): self
    {
        return $this->with(transport: $transport);
    }

    /**
     * withHttpClient sends requests through a PSR-18 client. Omitted PSR-17
     * factories are discovered. PSR-18 clients that buffer the response body
     * (Guzzle by default) deliver stream chunks only once the reply is
     * complete; symfony/http-client streams incrementally.
     */
    public function withHttpClient(
        ClientInterface $client,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): self {
        return $this->with(
            httpClient: $client,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
        );
    }

    /** withValue stores a provider-specific setting. */
    public function withValue(string $key, mixed $value): self
    {
        $values = $this->values;
        $values[$key] = $value;

        return $this->with(values: $values);
    }

    /** value returns the setting stored by withValue, or null. */
    public function value(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /** stringValue returns a string setting, or '' when absent. */
    public function stringValue(string $key): string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private function headerKey(string $name): ?string
    {
        foreach (array_keys($this->headers) as $key) {
            if (strcasecmp($key, $name) === 0) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param array<string,list<string>>|null $headers
     * @param array<string,mixed>|null        $values
     */
    private function with(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?array $headers = null,
        ?float $timeout = null,
        ?Transport $transport = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?array $values = null,
    ): self {
        return new self(
            $apiKey ?? $this->apiKey,
            $baseUrl ?? $this->baseUrl,
            $headers ?? $this->headers,
            $timeout ?? $this->timeout,
            $transport ?? $this->transport,
            $httpClient ?? $this->httpClient,
            $requestFactory ?? $this->requestFactory,
            $streamFactory ?? $this->streamFactory,
            $values ?? $this->values,
        );
    }
}
