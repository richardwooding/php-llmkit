<?php

declare(strict_types=1);

namespace LlmKit\Http;

use Generator;

/**
 * Ndjson reads a newline-delimited JSON body.
 *
 * @internal
 */
final class Ndjson
{
    /**
     * lines yields each non-empty line of a stream of byte chunks.
     *
     * @param iterable<int,string> $chunks
     *
     * @return Generator<int,string,mixed,void>
     */
    public static function lines(iterable $chunks): Generator
    {
        $buffer = '';
        foreach ($chunks as $chunk) {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line !== '') {
                    yield $line;
                }
            }
        }
        $line = trim($buffer);
        if ($line !== '') {
            yield $line;
        }
    }
}
