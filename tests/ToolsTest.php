<?php

declare(strict_types=1);

namespace LlmKit\Tests;

use LlmKit\Exception\ToolLoopExceededException;
use LlmKit\FinishReason;
use LlmKit\Message;
use LlmKit\Request;
use LlmKit\Response;
use LlmKit\Role;
use LlmKit\Tests\Support\FakeClient;
use LlmKit\TextPart;
use LlmKit\ToolCall;
use LlmKit\Tools;
use LlmKit\Usage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Tools::class)]
final class ToolsTest extends TestCase
{
    public function testRunFeedsResultsBackAndSumsUsage(): void
    {
        $client = new FakeClient('fake', 'fake-1', [
            new Response(
                Message::assistant(new ToolCall('call_1', 'weather', '{"city":"Cape Town"}')),
                FinishReason::ToolCalls,
                new Usage(10, 5, 15),
            ),
            new Response(Message::assistantText('Sunny.'), FinishReason::Stop, new Usage(20, 3, 23)),
        ]);
        $request = Request::prompt('Weather in Cape Town?');

        $seen = [];
        $response = Tools::run($client, $request, [
            'weather' => function (array $args) use (&$seen): string {
                $seen[] = $args;

                return 'sunny, 24C';
            },
        ]);

        self::assertSame([['city' => 'Cape Town']], $seen);
        self::assertSame('Sunny.', $response->text());
        self::assertSame(30, $response->usage->inputTokens);
        self::assertSame(8, $response->usage->outputTokens);
        self::assertSame(38, $response->usage->totalTokens);

        self::assertCount(3, $request->messages);
        self::assertSame(Role::Assistant, $request->messages[1]->role);
        self::assertSame(Role::Tool, $request->messages[2]->role);
        $result = $request->messages[2]->toolResults()[0];
        self::assertSame('call_1', $result->callId);
        self::assertSame('sunny, 24C', $result->toText());
        self::assertFalse($result->isError);
    }

    public function testUnknownToolIsReportedToTheModel(): void
    {
        $client = new FakeClient('fake', 'fake-1', [
            new Response(Message::assistant(new ToolCall('call_1', 'ghost')), FinishReason::ToolCalls),
            new Response(Message::assistantText('done')),
        ]);
        $request = new Request([Message::userText('go')]);

        Tools::run($client, $request, []);

        $result = $request->messages[2]->toolResults()[0];
        self::assertTrue($result->isError);
        self::assertSame('unknown tool "ghost"', $result->toText());
    }

    public function testToolFailureIsReportedToTheModel(): void
    {
        $client = new FakeClient('fake', 'fake-1', [
            new Response(Message::assistant(new ToolCall('call_1', 'boom')), FinishReason::ToolCalls),
            new Response(Message::assistantText('recovered')),
        ]);
        $request = new Request([Message::userText('go')]);

        $response = Tools::run($client, $request, [
            'boom' => static fn(): string => throw new RuntimeException('tool exploded'),
        ]);

        self::assertSame('recovered', $response->text());
        $result = $request->messages[2]->toolResults()[0];
        self::assertTrue($result->isError);
        self::assertSame('tool exploded', $result->toText());
    }

    public function testLoopCapIsEnforced(): void
    {
        $responses = [];
        for ($i = 0; $i < 3; ++$i) {
            $responses[] = new Response(
                Message::assistant(new ToolCall('call_' . $i, 'again')),
                FinishReason::ToolCalls,
            );
        }
        $client = new FakeClient('fake', 'fake-1', $responses);

        $this->expectException(ToolLoopExceededException::class);
        Tools::run($client, new Request([Message::userText('go')]), [
            'again' => static fn(): string => 'more',
        ], 3);
    }

    public function testResponseWithoutToolCallsIsReturnedImmediately(): void
    {
        $client = FakeClient::text('hello');
        $request = new Request([Message::userText('hi')]);

        $response = Tools::run($client, $request, ['unused' => static fn(): string => '']);

        self::assertEquals([new TextPart('hello')], $response->message->parts);
        self::assertCount(1, $request->messages);
    }
}
