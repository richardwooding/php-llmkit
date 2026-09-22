<?php

declare(strict_types=1);

namespace LlmKit\Tests\Http;

use LlmKit\Config;
use LlmKit\Exception\ApiException;
use LlmKit\Http\Endpoint;
use LlmKit\Http\MockTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Endpoint::class)]
#[CoversClass(MockTransport::class)]
final class EndpointTest extends TestCase
{
    private function endpoint(MockTransport $transport, ?Config $config = null): Endpoint
    {
        $config = ($config ?? new Config())->withTransport($transport);

        return Endpoint::create('test', $config, 'https://api.example.test/v1/');
    }

    public function testPostJsonSendsHeadersAndReturnsTheBody(): void
    {
        $transport = new MockTransport();
        $transport->pushJson(['ok' => true]);

        $endpoint = $this->endpoint($transport)->withBearerAuth('sk-123');
        $raw = $endpoint->postJson('/chat/completions', '{"model":"x"}');

        self::assertSame('{"ok":true}', $raw);
        $request = $transport->lastRequest();
        self::assertSame('https://api.example.test/v1/chat/completions', $request->url);
        self::assertSame('POST', $request->method);
        self::assertSame(['Bearer sk-123'], $request->headers['Authorization']);
        self::assertSame(['application/json'], $request->headers['Content-Type']);
        self::assertSame(['application/json, text/event-stream'], $request->headers['Accept']);
    }

    public function testConfiguredHeadersAndTimeoutAreApplied(): void
    {
        $transport = new MockTransport();
        $transport->pushJson([]);

        $config = (new Config())->withHeader('X-Title', 'demo')->withTimeout(2.5);
        $this->endpoint($transport, $config)->postJson('/x', '{}');

        $request = $transport->lastRequest();
        self::assertSame(['demo'], $request->headers['X-Title']);
        self::assertSame(2.5, $request->timeout);
    }

    public function testAuthCallbackRunsOnEveryRequest(): void
    {
        $transport = new MockTransport();
        $transport->pushJson([])->pushJson([]);
        $calls = 0;

        $endpoint = $this->endpoint($transport)->withAuth(function () use (&$calls): array {
            ++$calls;

            return ['Authorization' => 'Bearer token-' . $calls];
        });
        $endpoint->postJson('/x', '{}');
        $endpoint->postJson('/x', '{}');

        self::assertSame(2, $calls);
        self::assertSame(['Bearer token-2'], $transport->lastRequest()->headers['Authorization']);
    }

    public function testDefaultHeaderDoesNotOverrideTheCaller(): void
    {
        $transport = new MockTransport();
        $transport->pushJson([]);

        $config = (new Config())->withHeader('anthropic-version', '2024-01-01');
        $this->endpoint($transport, $config)
            ->withDefaultHeader('anthropic-version', '2023-06-01')
            ->withDefaultHeader('x-extra', 'yes')
            ->postJson('/x', '{}');

        $request = $transport->lastRequest();
        self::assertSame(['2024-01-01'], $request->headers['anthropic-version']);
        self::assertSame(['yes'], $request->headers['x-extra']);
    }

    public function testAbsoluteUrlsBypassTheBaseUrl(): void
    {
        $transport = new MockTransport();
        $transport->pushJson([]);

        $this->endpoint($transport)->postJson('https://other.test/v2/models', '{}');

        self::assertSame('https://other.test/v2/models', $transport->lastRequest()->url);
    }

    public function testErrorStatusBecomesAnApiException(): void
    {
        $transport = new MockTransport();
        $transport->push('{"error":{"message":"nope","code":"bad_request"}}', 400);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('test: HTTP 400 [bad_request]: nope');
        $this->endpoint($transport)->postJson('/x', '{}');
    }

    public function testStreamErrorStatusBecomesAnApiExceptionOnFirstRead(): void
    {
        $transport = new MockTransport();
        $transport->push('{"error":{"message":"overloaded"}}', 529);

        $stream = $this->endpoint($transport)->postSse('/x', '{}');
        self::assertCount(0, $transport->requests());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('overloaded');
        iterator_to_array($stream);
    }

    public function testStreamYieldsEventsLazily(): void
    {
        $transport = new MockTransport();
        $transport->pushSse("data: {\"a\":1}\n\ndata: {\"b\":2}\n\n", chunk: 5);

        $events = [];
        foreach ($this->endpoint($transport)->postSse('/x', '{}') as $event) {
            $events[] = $event->data;
        }

        self::assertSame(['{"a":1}', '{"b":2}'], $events);
    }
}
