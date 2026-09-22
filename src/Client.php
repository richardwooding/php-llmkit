<?php

declare(strict_types=1);

namespace LlmKit;

/**
 * Client is the handle a Provider returns. Check it against Chatter,
 * Streamer, Embedder and the other capability interfaces to use it; a
 * provider only implements what its API supports, so the method set is the
 * capability matrix.
 */
interface Client
{
    /** provider returns the provider ID this client speaks to. */
    public function provider(): string;

    /** model returns the model name. */
    public function model(): string;
}
