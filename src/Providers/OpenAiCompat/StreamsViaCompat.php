<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenAiCompat;

use Generator;
use LlmKit\Request;

/**
 * StreamsViaCompat implements Streamer over a CompatClient.
 *
 * @internal
 */
trait StreamsViaCompat
{
    /** @return Generator<int,\LlmKit\Chunk,mixed,void> */
    public function stream(Request $request): Generator
    {
        yield from $this->inner->stream($request);
    }
}
