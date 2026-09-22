<?php

declare(strict_types=1);

namespace LlmKit;

/** Embedder turns text into vectors. */
interface Embedder extends Client
{
    public function embed(EmbedRequest $request): EmbedResponse;
}
