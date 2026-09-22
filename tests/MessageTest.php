<?php

declare(strict_types=1);

namespace LlmKit\Tests;

use LlmKit\Exception\InvalidRequestException;
use LlmKit\FilePart;
use LlmKit\ImagePart;
use LlmKit\Message;
use LlmKit\ReasoningPart;
use LlmKit\Role;
use LlmKit\TextPart;
use LlmKit\ToolCall;
use LlmKit\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Message::class)]
final class MessageTest extends TestCase
{
    public function testTextConcatenatesTextPartsOnly(): void
    {
        $message = Message::assistant(
            new ReasoningPart('thinking'),
            new TextPart('Hello '),
            new TextPart('world'),
            new ToolCall('call_1', 'weather', '{"city":"Cape Town"}'),
        );

        self::assertSame('Hello world', $message->text());
    }

    public function testToolCallsAndResultsAreReturnedInOrder(): void
    {
        $message = Message::assistant(
            new ToolCall('call_1', 'a'),
            new TextPart('x'),
            new ToolCall('call_2', 'b'),
        );

        self::assertSame(['call_1', 'call_2'], array_map(
            static fn(ToolCall $c): string => $c->id,
            $message->toolCalls(),
        ));

        $tool = Message::tool(
            ToolResult::text('call_1', 'a', 'first'),
            ToolResult::error('call_2', 'b', 'boom'),
        );
        self::assertCount(2, $tool->toolResults());
        self::assertSame('first', $tool->toolResults()[0]->toText());
        self::assertTrue($tool->toolResults()[1]->isError);
        self::assertSame(Role::Tool, $tool->role);
    }

    public function testToolCallArgumentsDecode(): void
    {
        $call = new ToolCall('call_1', 'weather', '{"city":"Cape Town"}');
        self::assertSame(['city' => 'Cape Town'], $call->argumentsArray());

        $empty = new ToolCall('call_2', 'weather');
        self::assertSame([], $empty->argumentsArray());
        self::assertSame('{}', $empty->argumentsJson());
    }

    public function testJsonRoundTripKeepsEveryPartType(): void
    {
        $message = new Message(Role::User, [
            new TextPart('look'),
            new ImagePart("\x89PNG\x00binary", 'image/png', detail: 'high'),
            ImagePart::url('https://example.test/a.png'),
            new FilePart('%PDF-1.7', 'application/pdf', name: 'report.pdf'),
            new ReasoningPart('thought', 'sig', 'enc'),
            new ToolCall('call_1', 'weather', '{"city":"Cape Town"}'),
            new ToolResult('call_1', 'weather', [new TextPart('sunny')], true),
        ], 'richard');

        $decoded = Message::fromJson($message->toJson());

        self::assertSame(Role::User, $decoded->role);
        self::assertSame('richard', $decoded->name);
        self::assertEquals($message->parts, $decoded->parts);
    }

    public function testJsonUsesTypeDiscriminatorsAndBase64(): void
    {
        $message = Message::user(new ImagePart('bytes', 'image/png'));
        /** @var array{parts: list<array<string,mixed>>} $data */
        $data = json_decode($message->toJson(), true);

        self::assertSame('image', $data['parts'][0]['type']);
        self::assertSame(base64_encode('bytes'), $data['parts'][0]['data']);
    }

    public function testUnknownPartTypeIsRejected(): void
    {
        $this->expectException(InvalidRequestException::class);
        Message::fromJson('{"role":"user","parts":[{"type":"hologram"}]}');
    }

    public function testMissingRoleIsRejected(): void
    {
        $this->expectException(InvalidRequestException::class);
        Message::fromJson('{"parts":[]}');
    }
}
