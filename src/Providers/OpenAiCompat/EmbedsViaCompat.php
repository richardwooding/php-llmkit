<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenAiCompat;

use LlmKit\EmbedRequest;
use LlmKit\EmbedResponse;

/**
 * EmbedsViaCompat implements Embedder over a CompatClient.
 *
 * @internal
 */
trait EmbedsViaCompat
{
    public function embed(EmbedRequest $request): EmbedResponse
    {
        return $this->inner->embed($request);
    }
}
