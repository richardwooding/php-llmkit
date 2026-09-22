<?php

declare(strict_types=1);

namespace LlmKit\Catalog;

/**
 * Seed is the catalog's built-in data, checked on Catalog::DATA_AS_OF against
 * the vendors' own pages. Rows whose limits or prices could not be read from
 * an official page are left out rather than estimated.
 *
 * Sources:
 *   - Anthropic: https://platform.claude.com/docs/en/about-claude/pricing
 *     (input, 5m cache write, cache read, output) and the per-model pages.
 *     Cache write is the 5-minute rate; the 1-hour rate is 2x input.
 *   - OpenAI: https://developers.openai.com/api/docs/pricing and the per-model
 *     pages. Cache writes carry no premium, so cacheWrite = input.
 *   - Gemini (provider "vertex"): https://ai.google.dev/gemini-api/docs/pricing
 *     (paid tier, prompts <= 200k tokens where tiered). Cache writes are billed
 *     at the input rate plus hourly storage, which cost() does not model.
 *   - DeepSeek: https://api-docs.deepseek.com/quick_start/pricing, peak-hour
 *     rates (off-peak is half). Cache writes carry no premium.
 *   - xAI: https://docs.x.ai/docs/models, standard tier for prompts under
 *     200k tokens; max output is not published (0).
 *   - Groq: https://console.groq.com/docs/models.
 *
 * Not seeded: Cohere (current Command models are on custom pricing), Ollama
 * (local; limits depend on the pulled tag), OpenRouter and Hugging Face
 * (pass-through pricing per upstream model).
 *
 * @internal
 */
final class Seed
{
    /** @return list<Model> */
    public static function models(): array
    {
        return [
            // Anthropic
            self::claude('claude-fable-5-1', 'fable', 'Claude Fable 5.1', 1_000_000, 128_000, new Pricing(10, 50, 0.25, 12.5)),
            self::claude('claude-fable-5', 'fable', 'Claude Fable 5', 1_000_000, 128_000, new Pricing(10, 50, 1, 12.5)),
            self::claude('claude-opus-5', 'opus', 'Claude Opus 5', 1_000_000, 128_000, new Pricing(5, 25, 0.5, 6.25)),
            self::claude('claude-opus-4-8', 'opus', 'Claude Opus 4.8', 1_000_000, 128_000, new Pricing(5, 25, 0.5, 6.25)),
            self::claude('claude-opus-4-7', 'opus', 'Claude Opus 4.7', 1_000_000, 128_000, new Pricing(5, 25, 0.5, 6.25)),
            self::claude('claude-opus-4-6', 'opus', 'Claude Opus 4.6', 1_000_000, 128_000, new Pricing(5, 25, 0.5, 6.25)),
            self::claude('claude-opus-4-5-20251101', 'opus', 'Claude Opus 4.5', 200_000, 64_000, new Pricing(5, 25, 0.5, 6.25), ['claude-opus-4-5']),
            self::claude('claude-sonnet-5', 'sonnet', 'Claude Sonnet 5', 1_000_000, 128_000, new Pricing(2, 10, 0.2, 2.5)),
            self::claude('claude-sonnet-4-6', 'sonnet', 'Claude Sonnet 4.6', 1_000_000, 128_000, new Pricing(3, 15, 0.3, 3.75)),
            self::claude('claude-sonnet-4-5-20250929', 'sonnet', 'Claude Sonnet 4.5', 200_000, 64_000, new Pricing(3, 15, 0.3, 3.75), ['claude-sonnet-4-5']),
            self::claude('claude-haiku-4-5-20251001', 'haiku', 'Claude Haiku 4.5', 200_000, 64_000, new Pricing(1, 5, 0.1, 1.25), ['claude-haiku-4-5']),

            // OpenAI (Responses API)
            self::gpt('gpt-5.5', 'gpt-5', 'GPT-5.5', 1_050_000, 128_000, 5, 0.5, 30, true),
            self::gpt('gpt-5.4', 'gpt-5', 'GPT-5.4', 1_050_000, 128_000, 2.5, 0.25, 15, true),
            self::gpt('gpt-5', 'gpt-5', 'GPT-5', 400_000, 128_000, 1.25, 0.125, 10, true),
            self::gpt('gpt-5-mini', 'gpt-5', 'GPT-5 mini', 400_000, 128_000, 0.25, 0.025, 2, true),
            self::gpt('gpt-5-nano', 'gpt-5', 'GPT-5 nano', 400_000, 128_000, 0.05, 0.005, 0.4, true),
            self::gpt('gpt-4.1', 'gpt-4.1', 'GPT-4.1', 1_047_576, 32_768, 2, 0.5, 8, false),
            self::gpt('gpt-4o', 'gpt-4o', 'GPT-4o', 128_000, 16_384, 2.5, 1.25, 10, false),
            self::gpt('o3', 'o', 'o3', 200_000, 100_000, 2, 0.5, 8, true),
            self::gpt('o4-mini', 'o', 'o4-mini', 200_000, 100_000, 1.1, 0.275, 4.4, true),

            // Gemini via Vertex AI
            self::gemini('gemini-3.1-pro-preview', 'gemini-3', 'Gemini 3.1 Pro Preview', 2, 0.2, 12),
            self::gemini('gemini-3.5-flash', 'gemini-3', 'Gemini 3.5 Flash', 1.5, 0.15, 9),
            self::gemini('gemini-3.5-flash-lite', 'gemini-3', 'Gemini 3.5 Flash-Lite', 0.3, 0.03, 2.5),
            self::gemini('gemini-2.5-pro', 'gemini-2.5', 'Gemini 2.5 Pro', 1.25, 0.125, 10),
            self::gemini('gemini-2.5-flash', 'gemini-2.5', 'Gemini 2.5 Flash', 0.3, 0.03, 2.5),
            self::gemini('gemini-2.5-flash-lite', 'gemini-2.5', 'Gemini 2.5 Flash-Lite', 0.1, 0.01, 0.4),

            // DeepSeek
            new Model(
                id: 'deepseek-flash',
                provider: 'deepseek',
                family: 'deepseek-v4',
                displayName: 'DeepSeek V4.1 Flash',
                contextWindow: 1_000_000,
                maxOutput: 384_000,
                pricing: new Pricing(0.3, 1.2, 0.006, 0.3),
                capabilities: new Capabilities(tools: true, vision: true, reasoning: true, promptCache: true),
                aliases: ['deepseek-v4-flash'],
            ),
            new Model(
                id: 'deepseek-v4-pro',
                provider: 'deepseek',
                family: 'deepseek-v4',
                displayName: 'DeepSeek V4 Pro',
                contextWindow: 1_000_000,
                maxOutput: 384_000,
                pricing: new Pricing(1.32, 3.96, 0.044, 1.32),
                capabilities: new Capabilities(tools: true, reasoning: true, promptCache: true),
            ),

            // xAI
            self::grok('grok-4.6', 'Grok 4.6', 500_000, 2, 0.5, 6),
            self::grok('grok-4.5', 'Grok 4.5', 500_000, 2, 0.3, 6, ['grok-4.5-latest', 'grok-build-latest']),
            self::grok('grok-4.3', 'Grok 4.3', 1_000_000, 1.25, 0.2, 2.5, ['grok-4.3-latest']),
            self::grok('grok-build-0.1', 'Grok Build 0.1', 256_000, 1, 0.2, 2, ['grok-code-fast-1', 'grok-code-fast', 'grok-code-fast-1-0825']),

            // Groq
            self::gptOss('openai/gpt-oss-120b', 'GPT-OSS 120B', 0.15, 0.075, 0.6),
            self::gptOss('openai/gpt-oss-20b', 'GPT-OSS 20B', 0.075, 0.037, 0.3),
        ];
    }

    /** @param list<string> $aliases */
    private static function claude(
        string $id,
        string $family,
        string $name,
        int $context,
        int $maxOutput,
        Pricing $pricing,
        array $aliases = [],
    ): Model {
        return new Model(
            id: $id,
            provider: 'anthropic',
            family: $family,
            displayName: $name,
            contextWindow: $context,
            maxOutput: $maxOutput,
            pricing: $pricing,
            capabilities: new Capabilities(true, true, true, true, true),
            aliases: $aliases,
        );
    }

    private static function gpt(
        string $id,
        string $family,
        string $name,
        int $context,
        int $maxOutput,
        float $input,
        float $cached,
        float $output,
        bool $reasoning,
    ): Model {
        return new Model(
            id: $id,
            provider: 'openai',
            family: $family,
            displayName: $name,
            contextWindow: $context,
            maxOutput: $maxOutput,
            pricing: new Pricing($input, $output, $cached, $input),
            capabilities: new Capabilities(true, true, $reasoning, true, true),
        );
    }

    /** gemini rows share Gemini's 1,048,576-token input and 65,536-token output limits. */
    private static function gemini(
        string $id,
        string $family,
        string $name,
        float $input,
        float $cached,
        float $output,
    ): Model {
        return new Model(
            id: $id,
            provider: 'vertex',
            family: $family,
            displayName: $name,
            contextWindow: 1_048_576,
            maxOutput: 65_536,
            pricing: new Pricing($input, $output, $cached, $input),
            capabilities: new Capabilities(true, true, true, true, true),
        );
    }

    /** @param list<string> $aliases */
    private static function grok(
        string $id,
        string $name,
        int $context,
        float $input,
        float $cached,
        float $output,
        array $aliases = [],
    ): Model {
        return new Model(
            id: $id,
            provider: 'xai',
            family: 'grok-4',
            displayName: $name,
            contextWindow: $context,
            pricing: new Pricing($input, $output, $cached, $input),
            capabilities: new Capabilities(true, true, true, true, true),
            aliases: $aliases,
        );
    }

    private static function gptOss(string $id, string $name, float $input, float $cached, float $output): Model
    {
        return new Model(
            id: $id,
            provider: 'groq',
            family: 'gpt-oss',
            displayName: $name,
            contextWindow: 131_072,
            maxOutput: 65_536,
            pricing: new Pricing($input, $output, $cached, $input),
            capabilities: new Capabilities(tools: true, reasoning: true, jsonSchema: true, promptCache: true),
        );
    }
}
