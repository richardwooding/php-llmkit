<?php

declare(strict_types=1);

namespace LlmKit;

use Generator;

/**
 * Streamer produces an assistant response incrementally. The request is sent
 * when iteration starts; abandoning the generator closes the connection.
 * Failures are thrown from the generator. The last chunk is always of kind
 * ChunkKind::Finish unless an exception ends the sequence.
 */
interface Streamer extends Client
{
    /** @return Generator<int,Chunk,mixed,void> */
    public function stream(Request $request): Generator;
}
