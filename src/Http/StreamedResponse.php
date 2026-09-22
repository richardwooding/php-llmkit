<?php

declare(strict_types=1);

namespace LlmKit\Http;

use Generator;

/**
 * StreamedResponse carries a reply whose body arrives incrementally. The
 * status and headers are available as soon as they are; $chunks yields byte
 * chunks of the body, which are not aligned to any line or frame boundary.
 * Abandoning the generator closes the connection.
 *
 * @internal
 */
final readonly class StreamedResponse
{
    /**
     * @param Generator<int,string>     $chunks
     * @param array<string,list<string>> $headers
     */
    public function __construct(
        public int $status,
        public Generator $chunks,
        public array $headers = [],
    ) {}

    /** isSuccess reports whether the status is 2xx. */
    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** drain reads at most $limit bytes of the body, for error reporting. */
    public function drain(int $limit = 65536): string
    {
        $out = '';
        foreach ($this->chunks as $chunk) {
            $out .= $chunk;
            if (strlen($out) >= $limit) {
                break;
            }
        }

        return substr($out, 0, $limit);
    }
}
