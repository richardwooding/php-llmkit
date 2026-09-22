<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenAiCompat;

use LlmKit\Client;

/**
 * AbstractCompatBackedClient is the shared plumbing of the providers that
 * speak Chat Completions. Subclasses declare exactly the capability
 * interfaces their endpoint supports and mix in the matching traits, so the
 * method set stays the capability matrix.
 *
 * @internal
 */
abstract class AbstractCompatBackedClient implements Client
{
    protected function __construct(
        protected readonly string $id,
        protected readonly CompatClient $inner,
    ) {}

    public function provider(): string
    {
        return $this->id;
    }

    public function model(): string
    {
        return $this->inner->model();
    }
}
