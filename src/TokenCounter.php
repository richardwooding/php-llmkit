<?php

declare(strict_types=1);

namespace LlmKit;

/**
 * TokenCounter reports how many input tokens a request would consume, without
 * generating anything. Generation parameters on the Request are ignored;
 * messages, system prompt, tools and reasoning configuration count.
 */
interface TokenCounter extends Client
{
    public function countTokens(Request $request): int;
}
