<?php

declare(strict_types=1);

namespace LlmKit;

/** FinishReason says why generation stopped. */
enum FinishReason: string
{
    case Stop = 'stop';
    case Length = 'length';
    case ToolCalls = 'tool_calls';
    case ContentFilter = 'content_filter';
    case Other = 'other';
}
