<?php

declare(strict_types=1);

namespace LlmKit;

/** Role identifies the author of a Message. */
enum Role: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
