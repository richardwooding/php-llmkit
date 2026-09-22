<?php

declare(strict_types=1);

namespace LlmKit\Http;

/**
 * Event is one server-sent event. Multi-line data payloads are joined with
 * newlines; comment lines are dropped.
 *
 * @internal
 */
final readonly class Event
{
    public function __construct(
        public string $name = '',
        public string $data = '',
        public string $id = '',
    ) {}
}
