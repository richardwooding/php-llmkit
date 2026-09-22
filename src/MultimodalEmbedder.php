<?php

declare(strict_types=1);

namespace LlmKit;

/** MultimodalEmbedder embeds inputs that mix text with images or other media. */
interface MultimodalEmbedder extends Client
{
    public function embedMultimodal(MultimodalEmbedRequest $request): EmbedResponse;
}
