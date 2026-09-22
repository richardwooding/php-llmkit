<?php

declare(strict_types=1);

namespace LlmKit;

/** ChunkKind discriminates streaming chunks. */
enum ChunkKind: string
{
    case Text = 'text';
    case Reasoning = 'reasoning';
    case ToolCall = 'tool_call';
    case Finish = 'finish';
}
