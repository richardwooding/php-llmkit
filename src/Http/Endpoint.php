<?php

declare(strict_types=1);

namespace LlmKit\Http;

use Closure;
use Generator;
use LlmKit\Config;

/**
 * Endpoint funnels every provider request through one place that applies
 * auth, default headers, timeouts and error-envelope decoding.
 *
 * @internal
 */
final readonly class Endpoint
{
    /**
     * @param array<string,list<string>>         $headers
     * @param (Closure(): array<string,string>) $auth    evaluated per request, so tokens can refresh
     */
    private function __construct(
        public string $provider,
        public string $baseUrl,
        private Transport $transport,
        private array $headers,
        private ?float $timeout,
        private ?Closure $auth,
    ) {}

    /** create builds an Endpoint from a Config and the provider's default base URL. */
    public static function create(string $provider, Config $config, string $defaultBaseUrl): self
    {
        return new self(
            $provider,
            rtrim($config->baseUrl ?? $defaultBaseUrl, '/'),
            TransportFactory::resolve($config),
            $config->headers,
            $config->timeout,
            null,
        );
    }

    /** withBearerAuth sends "Authorization: Bearer <key>". */
    public function withBearerAuth(string $key): self
    {
        return $this->withAuth(static fn(): array => ['Authorization' => 'Bearer ' . $key]);
    }

    /** withHeaderAuth sends the credential in an arbitrary header. */
    public function withHeaderAuth(string $name, string $value): self
    {
        return $this->withAuth(static fn(): array => [$name => $value]);
    }

    /**
     * withAuth sets the callback that supplies credential headers. It runs on
     * every request and may perform I/O, such as refreshing a token.
     *
     * @param Closure(): array<string,string> $auth
     */
    public function withAuth(Closure $auth): self
    {
        return new self(
            $this->provider,
            $this->baseUrl,
            $this->transport,
            $this->headers,
            $this->timeout,
            $auth,
        );
    }

    /** withDefaultHeader adds a header unless the caller already set one. */
    public function withDefaultHeader(string $name, string $value): self
    {
        foreach (array_keys($this->headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return $this;
            }
        }
        $headers = $this->headers;
        $headers[$name] = [$value];

        return new self(
            $this->provider,
            $this->baseUrl,
            $this->transport,
            $headers,
            $this->timeout,
            $this->auth,
        );
    }

    /** postJson sends $body and returns the response body of a 2xx reply. */
    public function postJson(string $path, string $body): string
    {
        $response = $this->transport->send($this->request($path, $body));
        if (!$response->isSuccess()) {
            throw ErrorParser::parse($this->provider, $response->status, $response->headers, $response->body);
        }

        return $response->body;
    }

    /**
     * postStream sends $body and yields byte chunks of a 2xx reply. The
     * request goes out when iteration starts.
     *
     * @return Generator<int,string,mixed,void>
     */
    public function postStream(string $path, string $body): Generator
    {
        $response = $this->transport->openStream($this->request($path, $body));
        if (!$response->isSuccess()) {
            throw ErrorParser::parse(
                $this->provider,
                $response->status,
                $response->headers,
                $response->drain(),
            );
        }
        yield from $response->chunks;
    }

    /**
     * postSse sends $body and yields the server-sent events of the reply.
     *
     * @return Generator<int,Event,mixed,void>
     */
    public function postSse(string $path, string $body): Generator
    {
        yield from Sse::events($this->postStream($path, $body));
    }

    /**
     * postNdjson sends $body and yields the newline-delimited JSON lines of
     * the reply.
     *
     * @return Generator<int,string,mixed,void>
     */
    public function postNdjson(string $path, string $body): Generator
    {
        yield from Ndjson::lines($this->postStream($path, $body));
    }

    private function request(string $path, string $body): HttpRequest
    {
        return new HttpRequest(
            $this->url($path),
            $body,
            $this->requestHeaders($body !== ''),
            $this->timeout,
        );
    }

    private function url(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return $this->baseUrl . $path;
    }

    /** @return array<string,list<string>> */
    private function requestHeaders(bool $hasBody): array
    {
        $headers = [];
        if ($hasBody) {
            $headers['Content-Type'] = ['application/json'];
        }
        $headers['Accept'] = ['application/json, text/event-stream'];
        foreach ($this->headers as $name => $values) {
            $headers[$name] = $values;
        }
        if ($this->auth !== null) {
            foreach (($this->auth)() as $name => $value) {
                $headers[$name] = [$value];
            }
        }

        return $headers;
    }
}
