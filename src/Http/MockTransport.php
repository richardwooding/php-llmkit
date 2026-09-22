<?php

declare(strict_types=1);

namespace LlmKit\Http;

use Generator;
use LlmKit\Exception\TransportException;
use LlmKit\Internal\Json;
use LogicException;

/**
 * MockTransport replays queued replies and records what was sent, for tests
 * of this library and of code built on it.
 */
final class MockTransport implements Transport
{
    /** @var list<HttpRequest> */
    private array $requests = [];

    /** @var list<array{status:int,body:string,headers:array<string,list<string>>,chunk:int}> */
    private array $queue = [];

    /**
     * push queues a reply. $chunk splits the body for openStream, so frame
     * boundaries can be made to fall anywhere.
     *
     * @param array<string,string|list<string>> $headers
     */
    public function push(string $body, int $status = 200, array $headers = [], int $chunk = 0): self
    {
        $this->queue[] = [
            'status' => $status,
            'body' => $body,
            'headers' => Headers::normalise($headers),
            'chunk' => $chunk,
        ];

        return $this;
    }

    /**
     * pushJson queues a JSON reply.
     *
     * @param array<string,mixed> $data
     */
    public function pushJson(array $data, int $status = 200): self
    {
        return $this->push(Json::encode($data), $status, ['Content-Type' => 'application/json']);
    }

    /** pushSse queues an event-stream reply. */
    public function pushSse(string $body, int $status = 200, int $chunk = 0): self
    {
        return $this->push($body, $status, ['Content-Type' => 'text/event-stream'], $chunk);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $reply = $this->next($request);

        return new HttpResponse($reply['status'], $reply['body'], $reply['headers']);
    }

    public function openStream(HttpRequest $request): StreamedResponse
    {
        $reply = $this->next($request);

        return new StreamedResponse($reply['status'], $this->chunks($reply['body'], $reply['chunk']), $reply['headers']);
    }

    /**
     * requests returns every request the transport received, in order.
     *
     * @return list<HttpRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /** lastRequest returns the most recent request. */
    public function lastRequest(): HttpRequest
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new LogicException('llmkit: no request was sent');
        }

        return $last;
    }

    /**
     * lastBody returns the most recent request body, decoded.
     *
     * @return array<string,mixed>
     */
    public function lastBody(): array
    {
        return Json::decodeObject($this->lastRequest()->body, 'decode recorded request body');
    }

    /** @return array{status:int,body:string,headers:array<string,list<string>>,chunk:int} */
    private function next(HttpRequest $request): array
    {
        $this->requests[] = $request;
        $reply = array_shift($this->queue);
        if ($reply === null) {
            throw new TransportException('llmkit: MockTransport has no queued reply for ' . $request->url);
        }

        return $reply;
    }

    /** @return Generator<int,string> */
    private function chunks(string $body, int $size): Generator
    {
        if ($body === '') {
            return;
        }
        if ($size <= 0) {
            yield $body;

            return;
        }
        foreach (str_split($body, $size) as $chunk) {
            yield $chunk;
        }
    }
}
