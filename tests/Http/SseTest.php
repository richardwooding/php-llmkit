<?php

declare(strict_types=1);

namespace LlmKit\Tests\Http;

use LlmKit\Http\Event;
use LlmKit\Http\Sse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sse::class)]
final class SseTest extends TestCase
{
    /**
     * @param list<string> $chunks
     *
     * @return list<Event>
     */
    private static function events(array $chunks): array
    {
        return iterator_to_array(Sse::events($chunks), false);
    }

    public function testParsesNamedEventsAndData(): void
    {
        $events = self::events(["event: message_start\ndata: {\"a\":1}\n\ndata: {\"b\":2}\n\n"]);

        self::assertEquals([
            new Event('message_start', '{"a":1}'),
            new Event('', '{"b":2}'),
        ], $events);
    }

    public function testJoinsMultiLineDataAndDropsComments(): void
    {
        $events = self::events([": keep-alive\ndata: line one\ndata: line two\nid: 7\n\n"]);

        self::assertEquals([new Event('', "line one\nline two", '7')], $events);
    }

    public function testDoneSentinelEndsTheStream(): void
    {
        $events = self::events(["data: {\"a\":1}\n\ndata: [DONE]\n\ndata: {\"never\":true}\n\n"]);

        self::assertEquals([new Event('', '{"a":1}')], $events);
    }

    public function testHandlesCarriageReturnsAndMissingTrailingBlankLine(): void
    {
        $events = self::events(["data: {\"a\":1}\r\n\r\ndata: {\"b\":2}"]);

        self::assertEquals([new Event('', '{"a":1}'), new Event('', '{"b":2}')], $events);
    }

    /** @return iterable<string,array{int}> */
    public static function chunkSizes(): iterable
    {
        yield 'byte at a time' => [1];
        yield 'small frames' => [7];
        yield 'whole body' => [0];
    }

    #[DataProvider('chunkSizes')]
    public function testFrameBoundariesDoNotMatter(int $size): void
    {
        $body = "event: a\ndata: {\"x\":1}\n\nevent: b\ndata: {\"y\":2}\n\n";
        $chunks = $size === 0 ? [$body] : str_split($body, $size);

        self::assertEquals([
            new Event('a', '{"x":1}'),
            new Event('b', '{"y":2}'),
        ], self::events($chunks));
    }

    public function testLinesAreNotLengthLimited(): void
    {
        $payload = str_repeat('x', 100_000);
        $events = self::events(str_split("data: {\"text\":\"{$payload}\"}\n\n", 4096));

        self::assertCount(1, $events);
        self::assertSame('{"text":"' . $payload . '"}', $events[0]->data);
    }

    public function testValuelessFieldsAndBareColonsAreTolerated(): void
    {
        $events = self::events(["data:compact\n\n"]);

        self::assertEquals([new Event('', 'compact')], $events);
    }
}
