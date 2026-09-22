<?php

declare(strict_types=1);

namespace LlmKit\Tests\Http;

use LlmKit\Http\Ndjson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ndjson::class)]
final class NdjsonTest extends TestCase
{
    public function testYieldsEachNonEmptyLine(): void
    {
        $lines = iterator_to_array(Ndjson::lines(["{\"a\":1}\n\n{\"b\":2}\n"]), false);

        self::assertSame(['{"a":1}', '{"b":2}'], $lines);
    }

    public function testYieldsTrailingLineWithoutNewline(): void
    {
        $lines = iterator_to_array(Ndjson::lines(['{"a":1}', "\n", '{"b":2}']), false);

        self::assertSame(['{"a":1}', '{"b":2}'], $lines);
    }

    public function testSplitsAcrossArbitraryChunks(): void
    {
        $body = "{\"a\":1}\n{\"b\":2}\n{\"c\":3}\n";
        $lines = iterator_to_array(Ndjson::lines(str_split($body, 3)), false);

        self::assertSame(['{"a":1}', '{"b":2}', '{"c":3}'], $lines);
    }
}
