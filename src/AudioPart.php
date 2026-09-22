<?php

declare(strict_types=1);

namespace LlmKit;

/** AudioPart is inline audio. */
final readonly class AudioPart implements Part
{
    public function __construct(public string $data = '', public string $mime = '') {}
}
