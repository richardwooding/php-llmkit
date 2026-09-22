<?php

declare(strict_types=1);

namespace LlmKit;

/**
 * ReasoningConfig enables extended thinking where a provider supports it.
 * Effort is low, medium or high; budgetTokens caps thinking tokens.
 *
 * Summary controls how much of the thinking comes back. Anthropic
 * (thinking.display) accepts "summarized", "omitted" or "updates"; OpenAI
 * (reasoning.summary) accepts "auto", "concise" or "detailed". Each provider
 * maps the other's "show me something" value ("auto" <-> "summarized") so one
 * setting works across both; anything else is passed through verbatim.
 */
final readonly class ReasoningConfig
{
    public function __construct(
        public string $effort = '',
        public int $budgetTokens = 0,
        public string $summary = '',
    ) {}
}
