<?php

declare(strict_types=1);

namespace LlmKit\Tests;

use LlmKit\Chatter;
use LlmKit\Config;
use LlmKit\Embedder;
use LlmKit\Exception\UnsupportedException;
use LlmKit\Http\MockTransport;
use LlmKit\LlmKit;
use LlmKit\Providers\Anthropic\AnthropicClient;
use LlmKit\Providers\OpenAiCompat\CompatProvider;
use LlmKit\Providers\OpenAiCompat\EndpointConfig;
use LlmKit\Providers\OpenAiCompat\Quirks;
use LlmKit\Request;
use LlmKit\TokenCounter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LlmKit::class)]
final class LlmKitTest extends TestCase
{
    protected function tearDown(): void
    {
        LlmKit::setRegistry(null);
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function defaultResolution(): iterable
    {
        yield 'openai' => ['gpt-5', 'openai', 'gpt-5'];
        yield 'anthropic' => ['claude-sonnet-4-5', 'anthropic', 'claude-sonnet-4-5'];
        yield 'vertex' => ['gemini-2.5-pro', 'vertex', 'gemini-2.5-pro'];
        yield 'deepseek' => ['deepseek-reasoner', 'deepseek', 'deepseek-reasoner'];
        yield 'xai' => ['grok-4', 'xai', 'grok-4'];
        yield 'cohere' => ['command-a-03-2025', 'cohere', 'command-a-03-2025'];
        yield 'voyage' => ['voyage-3-large', 'voyage', 'voyage-3-large'];
        yield 'ollama by tag' => ['llama3.2:3b', 'ollama', 'llama3.2:3b'];
        yield 'huggingface by path' => ['meta-llama/Llama-3.3-70B', 'huggingface', 'meta-llama/Llama-3.3-70B'];
        yield 'hf alias' => ['hf/meta-llama/Llama-3.3', 'huggingface', 'meta-llama/Llama-3.3'];
        yield 'groq needs its prefix' => ['groq/llama-3.3-70b', 'groq', 'llama-3.3-70b'];
        yield 'openrouter needs its prefix' => ['openrouter/openai/gpt-4o', 'openrouter', 'openai/gpt-4o'];
        yield 'vertex gRPC style prefix is not registered' => ['gemini-2.5-flash', 'vertex', 'gemini-2.5-flash'];
        yield 'unknown falls back to ollama' => ['mystery-model', 'ollama', 'mystery-model'];
    }

    #[DataProvider('defaultResolution')]
    public function testDefaultRegistryResolution(string $name, string $provider, string $model): void
    {
        [$found, $resolved] = LlmKit::parseModel($name);

        self::assertSame($provider, $found->id());
        self::assertSame($model, $resolved);
    }

    public function testOpenChecksCapabilitiesBeforeAnyCall(): void
    {
        $config = (new Config())->withTransport(new MockTransport())->withApiKey('sk-test');

        $counter = LlmKit::open(TokenCounter::class, 'claude-opus-5', $config);
        self::assertInstanceOf(AnthropicClient::class, $counter);

        $this->expectException(UnsupportedException::class);
        LlmKit::open(Embedder::class, 'claude-opus-5', $config);
    }

    public function testChatterGoesThroughTheResolvedProvider(): void
    {
        $transport = new MockTransport();
        $transport->pushJson([
            'content' => [['type' => 'text', 'text' => 'Hi there']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
        ]);
        $config = (new Config())->withTransport($transport)->withApiKey('sk-test');

        $response = LlmKit::chatter('claude-sonnet-4-5', $config)->chat(Request::prompt('hi'));

        self::assertSame('Hi there', $response->text());
        self::assertSame('https://api.anthropic.com/v1/messages', $transport->lastRequest()->url);
    }

    public function testCustomOpenAiCompatibleEndpointCanBeRegistered(): void
    {
        $transport = new MockTransport();
        $transport->pushJson([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'local'], 'finish_reason' => 'stop']],
        ]);

        LlmKit::register(new CompatProvider(new EndpointConfig(
            id: 'vllm',
            baseUrl: 'http://gpu-box:8000/v1',
            keyOptional: true,
            quirks: new Quirks(images: true, streamUsage: true),
        )));

        $client = LlmKit::open(Chatter::class, 'vllm/my-finetune', (new Config())->withTransport($transport));
        $response = $client->chat(Request::prompt('hi'));

        self::assertSame('local', $response->text());
        self::assertSame('vllm', $client->provider());
        self::assertSame('my-finetune', $client->model());
        self::assertSame('http://gpu-box:8000/v1/chat/completions', $transport->lastRequest()->url);
    }

    public function testFallbackCanBeChanged(): void
    {
        LlmKit::setFallback('huggingface');

        [$provider] = LlmKit::parseModel('mystery-model');
        self::assertSame('huggingface', $provider->id());
    }
}
