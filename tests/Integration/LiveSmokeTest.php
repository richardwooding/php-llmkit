<?php

declare(strict_types=1);

namespace LlmKit\Tests\Integration;

use LlmKit\ChunkKind;
use LlmKit\Config;
use LlmKit\EmbedRequest;
use LlmKit\LlmKit;
use LlmKit\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Live smoke tests against the real providers. They are excluded from the
 * default suite; run them with `composer test:integration` and the relevant
 * keys in the environment. Each case skips itself when its key is missing.
 */
#[Group('integration')]
final class LiveSmokeTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function chatModels(): iterable
    {
        yield 'openai' => ['gpt-5-nano', 'OPENAI_API_KEY'];
        yield 'anthropic' => ['claude-haiku-4-5', 'ANTHROPIC_API_KEY'];
        yield 'deepseek' => ['deepseek-chat', 'DEEPSEEK_API_KEY'];
        yield 'groq' => ['groq/llama-3.3-70b-versatile', 'GROQ_API_KEY'];
        yield 'xai' => ['grok-4', 'XAI_API_KEY'];
        yield 'cohere' => ['command-a-03-2025', 'COHERE_API_KEY'];
        yield 'openrouter' => ['openrouter/openai/gpt-4o-mini', 'OPENROUTER_API_KEY'];
    }

    #[DataProvider('chatModels')]
    public function testChat(string $model, string $env): void
    {
        $this->requireEnv($env);

        $response = LlmKit::chatter($model, $this->config())
            ->chat(new Request([\LlmKit\Message::userText('Reply with the single word: pong')], maxTokens: 64));

        self::assertNotSame('', trim($response->text()));
    }

    #[DataProvider('chatModels')]
    public function testStream(string $model, string $env): void
    {
        $this->requireEnv($env);

        $kinds = [];
        foreach (LlmKit::streamer($model, $this->config())->stream(Request::prompt('Count to three.')) as $chunk) {
            $kinds[] = $chunk->kind;
        }

        self::assertSame(ChunkKind::Finish, end($kinds));
        self::assertContains(ChunkKind::Text, $kinds);
    }

    /** @return iterable<string,array{string,string}> */
    public static function embedModels(): iterable
    {
        yield 'openai' => ['text-embedding-3-small', 'OPENAI_API_KEY'];
        yield 'voyage' => ['voyage-3-large', 'VOYAGE_API_KEY'];
        yield 'cohere' => ['embed-v4.0', 'COHERE_API_KEY'];
    }

    #[DataProvider('embedModels')]
    public function testEmbed(string $model, string $env): void
    {
        $this->requireEnv($env);

        $response = LlmKit::embedder($model, $this->config())
            ->embed(new EmbedRequest(['first document', 'second document']));

        self::assertCount(2, $response->embeddings);
        self::assertNotEmpty($response->embeddings[0]);
    }

    public function testAnthropicCountsTokens(): void
    {
        $this->requireEnv('ANTHROPIC_API_KEY');

        $count = LlmKit::tokenCounter('claude-haiku-4-5', $this->config())
            ->countTokens(Request::prompt('How many tokens is this?'));

        self::assertGreaterThan(0, $count);
    }

    private function config(): Config
    {
        return (new Config())->withTimeout(120.0);
    }

    private function requireEnv(string $name): void
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            self::markTestSkipped($name . ' is not set');
        }
    }
}
