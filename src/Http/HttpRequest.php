<?php

declare(strict_types=1);

namespace LlmKit\Http;

/**
 * HttpRequest is one outbound provider request.
 *
 * @internal
 */
final readonly class HttpRequest
{
    /** @param array<string,list<string>> $headers */
    public function __construct(
        public string $url,
        public string $body = '',
        public array $headers = [],
        public ?float $timeout = null,
        public string $method = 'POST',
    ) {}
}
