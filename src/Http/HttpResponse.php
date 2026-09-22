<?php

declare(strict_types=1);

namespace LlmKit\Http;

/**
 * HttpResponse is a buffered provider reply.
 *
 * @internal
 */
final readonly class HttpResponse
{
    /** @param array<string,list<string>> $headers */
    public function __construct(
        public int $status,
        public string $body = '',
        public array $headers = [],
    ) {}

    /** isSuccess reports whether the status is 2xx. */
    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
