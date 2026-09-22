<?php

declare(strict_types=1);

namespace LlmKit;

/**
 * FilePart is a document such as a PDF, inline or by URL. Providers that
 * accept video route it through the same slot.
 */
final readonly class FilePart implements Part
{
    public function __construct(
        public string $data = '',
        public string $mime = '',
        public string $url = '',
        public string $name = '',
    ) {}

    /** url builds a FilePart that references a URL. */
    public static function url(string $url, string $mime = ''): self
    {
        return new self(mime: $mime, url: $url);
    }
}
