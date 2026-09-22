<?php

declare(strict_types=1);

namespace LlmKit;

/** TextPart is plain text. */
final readonly class TextPart implements Part
{
    public function __construct(public string $text) {}
}
