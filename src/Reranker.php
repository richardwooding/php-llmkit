<?php

declare(strict_types=1);

namespace LlmKit;

/** Reranker scores documents against a query. */
interface Reranker extends Client
{
    public function rerank(RerankRequest $request): RerankResponse;
}
