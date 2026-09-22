<?php

declare(strict_types=1);

namespace LlmKit;

/** ImagePart is an image supplied inline (data + MIME) or by URL. */
final readonly class ImagePart implements Part
{
    public function __construct(
        public string $data = '',
        public string $mime = '',
        public string $url = '',
        public string $detail = '',
    ) {}

    /** url builds an ImagePart that references a URL. */
    public static function url(string $url, string $detail = ''): self
    {
        return new self(url: $url, detail: $detail);
    }
}
