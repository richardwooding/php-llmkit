<?php

declare(strict_types=1);

namespace LlmKit\Tests;

use LlmKit\Chatter;
use LlmKit\Embedder;
use LlmKit\Exception\InvalidRequestException;
use LlmKit\Exception\UnknownProviderException;
use LlmKit\Exception\UnsupportedException;
use LlmKit\Registry;
use LlmKit\Tests\Support\FakeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Registry::class)]
final class RegistryTest extends TestCase
{
    private function registry(): Registry
    {
        $registry = new Registry(
            new FakeProvider('openai', ['gpt-', 'o1']),
            new FakeProvider('anthropic', ['claude']),
            new FakeProvider('groq'),
            new FakeProvider('ollama'),
        );
        $registry->register(new FakeProvider('huggingface'), 'hf');

        return $registry;
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function models(): iterable
    {
        yield 'bare prefix wins' => ['gpt-5', 'openai', 'gpt-5'];
        yield 'second provider' => ['claude-sonnet-4-5', 'anthropic', 'claude-sonnet-4-5'];
        yield 'explicit prefix' => ['groq/llama-3.3-70b', 'groq', 'llama-3.3-70b'];
        yield 'nested model path' => ['openrouter/openai/gpt-4o', 'ollama', 'openrouter/openai/gpt-4o'];
        yield 'alias' => ['hf/meta-llama/Llama-3.3', 'huggingface', 'meta-llama/Llama-3.3'];
        yield 'case-insensitive prefix' => ['ANTHROPIC/claude-x', 'anthropic', 'claude-x'];
        yield 'fallback' => ['mystery-model', 'ollama', 'mystery-model'];
        yield 'trailing slash is part of the name' => ['gpt-5/', 'openai', 'gpt-5/'];
    }

    #[DataProvider('models')]
    public function testParseModel(string $input, string $provider, string $model): void
    {
        [$found, $name] = $this->registry()->parseModel($input);

        self::assertSame($provider, $found->id());
        self::assertSame($model, $name);
    }

    public function testSetFallbackTakesPrecedence(): void
    {
        $registry = $this->registry();
        $registry->setFallback('groq');

        [$provider] = $registry->parseModel('mystery-model');
        self::assertSame('groq', $provider->id());
    }

    public function testMissingFallbackIsReported(): void
    {
        $registry = new Registry(new FakeProvider('openai', ['gpt-']));

        $this->expectException(UnknownProviderException::class);
        $registry->parseModel('mystery-model');
    }

    public function testEmptyModelIsRejected(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->registry()->parseModel('  ');
    }

    public function testReRegisteringReplacesInPlace(): void
    {
        $registry = $this->registry();
        $registry->register(new FakeProvider('anthropic', ['claude', 'sonnet']));

        self::assertCount(5, $registry->providers());
        [$provider, $model] = $registry->parseModel('sonnet-x');
        self::assertSame('anthropic', $provider->id());
        self::assertSame('sonnet-x', $model);
    }

    public function testOpenChecksCapability(): void
    {
        $registry = $this->registry();

        $chatter = $registry->open(Chatter::class, 'gpt-5');
        self::assertSame('gpt-5', $chatter->model());

        $this->expectException(UnsupportedException::class);
        $registry->open(Embedder::class, 'gpt-5');
    }

    public function testLookupIsCaseInsensitive(): void
    {
        self::assertNotNull($this->registry()->lookup('OpenAI'));
        self::assertNull($this->registry()->lookup('vertex'));
    }
}
