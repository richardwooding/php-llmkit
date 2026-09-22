<?php

declare(strict_types=1);

namespace LlmKit\Http;

use Generator;
use LlmKit\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Psr18Transport sends requests through any PSR-18 client.
 *
 * PSR-18 has no notion of an unbuffered response, so how promptly
 * openStream delivers chunks depends on the client: Guzzle reads the whole
 * body before sendRequest returns, while symfony/http-client (through
 * SymfonyTransport) hands over frames as they arrive. Per-call timeouts are
 * the client's own configuration and are ignored here.
 */
final readonly class Psr18Transport implements Transport
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $response = $this->dispatch($request);

        return new HttpResponse(
            $response->getStatusCode(),
            (string) $response->getBody(),
            Headers::normalise($response->getHeaders()),
        );
    }

    public function openStream(HttpRequest $request): StreamedResponse
    {
        $response = $this->dispatch($request);

        return new StreamedResponse(
            $response->getStatusCode(),
            $this->read($response),
            Headers::normalise($response->getHeaders()),
        );
    }

    /** @return Generator<int,string> */
    private function read(ResponseInterface $response): Generator
    {
        $body = $response->getBody();

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            while (!$body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    break;
                }
                yield $chunk;
            }
        } finally {
            $body->close();
        }
    }

    private function dispatch(HttpRequest $request): ResponseInterface
    {
        $psr = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $values) {
            $psr = $psr->withHeader($name, $values);
        }
        if ($request->body !== '') {
            $psr = $psr->withBody($this->streamFactory->createStream($request->body));
        }

        try {
            return $this->client->sendRequest($psr);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException('llmkit: ' . $e->getMessage(), 0, $e);
        }
    }
}
