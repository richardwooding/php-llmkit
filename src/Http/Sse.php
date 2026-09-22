<?php

declare(strict_types=1);

namespace LlmKit\Http;

use Generator;

/**
 * Sse reads a text/event-stream body.
 *
 * @internal
 */
final class Sse
{
    /**
     * events yields each event of a stream of byte chunks.
     *
     * @param iterable<int,string> $chunks
     *
     * @return Generator<int,Event,mixed,void>
     */
    public static function events(iterable $chunks): Generator
    {
        $parser = new SseParser();
        foreach ($chunks as $chunk) {
            foreach ($parser->feed($chunk) as $event) {
                yield $event;
            }
            if ($parser->isDone()) {
                return;
            }
        }
        foreach ($parser->finish() as $event) {
            yield $event;
        }
    }
}
