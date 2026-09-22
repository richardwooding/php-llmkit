<?php

declare(strict_types=1);

namespace LlmKit;

/**
 * ToolCallDelta is a fragment of a streamed tool call. Index is stable for one
 * call within a response; id and name arrive on the first fragment.
 */
final readonly class ToolCallDelta
{
    public function __construct(
        public int $index = 0,
        public string $id = '',
        public string $name = '',
        public string $arguments = '',
    ) {}
}
