<?php

declare(strict_types=1);

namespace LlmKit;

use LlmKit\Providers\Anthropic\AnthropicProvider;
use LlmKit\Providers\Cohere\CohereProvider;
use LlmKit\Providers\DeepSeek\DeepSeekProvider;
use LlmKit\Providers\Groq\GroqProvider;
use LlmKit\Providers\HuggingFace\HuggingFaceProvider;
use LlmKit\Providers\Ollama\OllamaProvider;
use LlmKit\Providers\OpenAi\OpenAiProvider;
use LlmKit\Providers\OpenRouter\OpenRouterProvider;
use LlmKit\Providers\Vertex\VertexProvider;
use LlmKit\Providers\Voyage\VoyageProvider;
use LlmKit\Providers\XAi\XAiProvider;

/**
 * LlmKit is the entry point: it holds the default Registry and opens clients
 * by model name.
 *
 *     $chat = LlmKit::chatter('claude-sonnet-4-5');
 *     $reply = $chat->chat(Request::prompt('Explain generators in one paragraph.'));
 *     echo $reply->text();
 */
final class LlmKit
{
    private static ?Registry $registry = null;

    /**
     * registry returns the default registry, built on first use with every
     * bundled provider in bare-name match priority: strict prefixes first,
     * then Ollama (tagged names and the fallback), then Hugging Face
     * ("org/model" names).
     */
    public static function registry(): Registry
    {
        if (self::$registry !== null) {
            return self::$registry;
        }
        $registry = new Registry(
            new OpenAiProvider(),
            new AnthropicProvider(),
            new VertexProvider(),
            new DeepSeekProvider(),
            new XAiProvider(),
            new CohereProvider(),
            new VoyageProvider(),
            new GroqProvider(),
            new OpenRouterProvider(),
            new OllamaProvider(),
        );
        $registry->register(new HuggingFaceProvider(), 'hf');

        return self::$registry = $registry;
    }

    /** setRegistry replaces the default registry, or restores it with null. */
    public static function setRegistry(?Registry $registry): void
    {
        self::$registry = $registry;
    }

    /** register adds a provider to the default registry. */
    public static function register(Provider $provider, string ...$aliases): void
    {
        self::registry()->register($provider, ...$aliases);
    }

    /** setFallback names the provider used for bare names nothing claims. */
    public static function setFallback(string $id): void
    {
        self::registry()->setFallback($id);
    }

    /**
     * parseModel resolves a model name against the default registry.
     *
     * @return array{Provider,string}
     */
    public static function parseModel(string $model): array
    {
        return self::registry()->parseModel($model);
    }

    /** client opens a client for $model without checking its capabilities. */
    public static function client(string $model, ?Config $config = null): Client
    {
        return self::registry()->client($model, $config);
    }

    /**
     * open resolves $model and checks the client against $capability, failing
     * with UnsupportedException before any network call.
     *
     * @template T of Client
     *
     * @param class-string<T> $capability
     *
     * @return T
     */
    public static function open(string $capability, string $model, ?Config $config = null): Client
    {
        return self::registry()->open($capability, $model, $config);
    }

    /** chatter opens a Chatter for $model. */
    public static function chatter(string $model, ?Config $config = null): Chatter
    {
        return self::open(Chatter::class, $model, $config);
    }

    /** streamer opens a Streamer for $model. */
    public static function streamer(string $model, ?Config $config = null): Streamer
    {
        return self::open(Streamer::class, $model, $config);
    }

    /** embedder opens an Embedder for $model. */
    public static function embedder(string $model, ?Config $config = null): Embedder
    {
        return self::open(Embedder::class, $model, $config);
    }

    /** multimodalEmbedder opens a MultimodalEmbedder for $model. */
    public static function multimodalEmbedder(string $model, ?Config $config = null): MultimodalEmbedder
    {
        return self::open(MultimodalEmbedder::class, $model, $config);
    }

    /** reranker opens a Reranker for $model. */
    public static function reranker(string $model, ?Config $config = null): Reranker
    {
        return self::open(Reranker::class, $model, $config);
    }

    /** tokenCounter opens a TokenCounter for $model. */
    public static function tokenCounter(string $model, ?Config $config = null): TokenCounter
    {
        return self::open(TokenCounter::class, $model, $config);
    }
}
