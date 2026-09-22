<?php

declare(strict_types=1);

namespace LlmKit\Tests;

use LlmKit\Chunk;
use LlmKit\ChunkKind;
use LlmKit\FinishReason;
use LlmKit\ReasoningDelta;
use LlmKit\ReasoningPart;
use LlmKit\Stream;
use LlmKit\TextPart;
use LlmKit\ToolCall;
use LlmKit\ToolCallDelta;
use LlmKit\Usage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Stream::class)]
final class StreamTest extends TestCase
{
    public function testCollectConcatenatesTextAndUsage(): void
    {
        $response = Stream::collect([
            Chunk::text('Hello'),
            Chunk::text(' world'),
            Chunk::finish(FinishReason::Stop, new Usage(10, 5, 15)),
        ]);

        self::assertSame('Hello world', $response->text());
        self::assertSame(FinishReason::Stop, $response->finishReason);
        self::assertSame(15, $response->usage->totalTokens);
        self::assertEquals([new TextPart('Hello world')], $response->message->parts);
    }

    public function testCollectReassemblesToolArgumentsByIndex(): void
    {
        $response = Stream::collect([
            Chunk::toolCall(new ToolCallDelta(index: 1, id: 'call_b', name: 'b')),
            Chunk::toolCall(new ToolCallDelta(index: 0, id: 'call_a', name: 'a')),
            Chunk::toolCall(new ToolCallDelta(index: 0, arguments: '{"city":')),
            Chunk::toolCall(new ToolCallDelta(index: 0, arguments: '"Cape Town"}')),
            Chunk::finish(FinishReason::ToolCalls),
        ]);

        self::assertEquals([
            new ToolCall('call_a', 'a', '{"city":"Cape Town"}'),
            new ToolCall('call_b', 'b', '{}'),
        ], $response->message->parts);
    }

    public function testCollectKeepsReasoningBlocksInOrderWithSignatures(): void
    {
        $response = Stream::collect([
            Chunk::reasoning(new ReasoningDelta(index: 0, text: 'first ')),
            Chunk::reasoning(new ReasoningDelta(index: 1, text: 'second')),
            Chunk::reasoning(new ReasoningDelta(index: 0, text: 'half')),
            Chunk::reasoning(new ReasoningDelta(index: 0, signature: 'sig-0')),
            Chunk::reasoning(new ReasoningDelta(index: 2, encrypted: 'redacted')),
            Chunk::text('answer'),
            Chunk::finish(FinishReason::Stop),
        ]);

        self::assertEquals([
            new ReasoningPart('first half', 'sig-0'),
            new ReasoningPart('second'),
            new ReasoningPart('', '', 'redacted'),
            new TextPart('answer'),
        ], $response->message->parts);
    }

    public function testCollectAcceptsReasoningChunksWithoutADelta(): void
    {
        $response = Stream::collect([
            new Chunk(ChunkKind::Reasoning, text: 'think '),
            new Chunk(ChunkKind::Reasoning, text: 'more'),
            Chunk::finish(FinishReason::Stop),
        ]);

        self::assertEquals([new ReasoningPart('think more')], $response->message->parts);
    }

    public function testCollectDefaultsFinishReason(): void
    {
        self::assertSame(FinishReason::Stop, Stream::collect([Chunk::text('hi')])->finishReason);
        self::assertSame(
            FinishReason::ToolCalls,
            Stream::collect([Chunk::toolCall(new ToolCallDelta(id: 'call_1', name: 'a'))])->finishReason,
        );
    }

    public function testTextYieldsOnlyTextFragments(): void
    {
        $chunks = [
            Chunk::reasoning(new ReasoningDelta(text: 'hidden')),
            Chunk::text('visible'),
            Chunk::finish(FinishReason::Stop),
        ];

        self::assertSame(['visible'], iterator_to_array(Stream::text($chunks)));
    }
}
