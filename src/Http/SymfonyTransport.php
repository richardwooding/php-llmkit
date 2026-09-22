<?php

declare(strict_types=1);

namespace LlmKit\Http;

use Generator;
use LlmKit\Exception\TransportException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * SymfonyTransport sends requests through symfony/http-client, which streams
 * response bodies as they arrive and honours per-call timeouts.
 */
final readonly class SymfonyTransport implements Transport
{
    public function __construct(private HttpClientInterface $client) {}

    /** create builds a transport over a default Symfony HTTP client. */
    public static function create(): self
    {
        return new self(HttpClient::create());
    }

    /** isAvailable reports whether symfony/http-client is installed. */
    public static function isAvailable(): bool
    {
        return interface_exists(HttpClientInterface::class) && class_exists(HttpClient::class);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $response = $this->client->request($request->method, $request->url, $this->options($request));

            return new HttpResponse(
                $response->getStatusCode(),
                $response->getContent(false),
                Headers::normalise($response->getHeaders(false)),
            );
        } catch (TransportExceptionInterface $e) {
            throw new TransportException('llmkit: ' . $e->getMessage(), 0, $e);
        }
    }

    public function openStream(HttpRequest $request): StreamedResponse
    {
        try {
            $response = $this->client->request(
                $request->method,
                $request->url,
                ['buffer' => false] + $this->options($request),
            );

            return new StreamedResponse(
                $response->getStatusCode(),
                $this->read($response),
                Headers::normalise($response->getHeaders(false)),
            );
        } catch (TransportExceptionInterface $e) {
            throw new TransportException('llmkit: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return Generator<int,string> */
    private function read(ResponseInterface $response): Generator
    {
        try {
            foreach ($this->client->stream($response) as $chunk) {
                $content = $chunk->getContent();
                if ($content !== '') {
                    yield $content;
                }
            }
        } catch (TransportExceptionInterface $e) {
            throw new TransportException('llmkit: stream: ' . $e->getMessage(), 0, $e);
        } finally {
            $response->cancel();
        }
    }

    /** @return array<string,mixed> */
    private function options(HttpRequest $request): array
    {
        $options = ['headers' => $request->headers];
        if ($request->body !== '') {
            $options['body'] = $request->body;
        }
        if ($request->timeout !== null) {
            $options['timeout'] = $request->timeout;
            $options['max_duration'] = $request->timeout;
        }

        return $options;
    }
}
