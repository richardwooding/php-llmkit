<?php

declare(strict_types=1);

namespace LlmKit\Tests\Providers;

use LlmKit\Chatter;
use LlmKit\Config;
use LlmKit\Embedder;
use LlmKit\EmbedRequest;
use LlmKit\Exception\UnsupportedException;
use LlmKit\Http\MockTransport;
use LlmKit\Message;
use LlmKit\Providers\DeepSeek\DeepSeekClient;
use LlmKit\Providers\DeepSeek\DeepSeekProvider;
use LlmKit\Providers\Groq\GroqClient;
use LlmKit\Providers\Groq\GroqProvider;
use LlmKit\Providers\HuggingFace\HuggingFaceClient;
use LlmKit\Providers\HuggingFace\HuggingFaceProvider;
use LlmKit\Providers\OpenRouter\OpenRouterClient;
use LlmKit\Providers\OpenRouter\OpenRouterProvider;
use LlmKit\Providers\XAi\XAiClient;
use LlmKit\Providers\XAi\XAiProvider;
use LlmKit\ReasoningConfig;
use LlmKit\Request;
use LlmKit\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeepSeekClient::class)]
#[CoversClass(GroqClient::class)]
#[CoversClass(XAiClient::class)]
#[CoversClass(OpenRouterClient::class)]
#[CoversClass(HuggingFaceClient::class)]
final class CompatBackedProvidersTest extends TestCase
{
    private MockTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
    }

    private function config(): Config
    {
        return (new Config())->withTransport($this->transport)->withApiKey('sk-test');
    }

    private function queueChat(): void
    {
        $this->transport->pushJson([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
        ]);
    }

    /** @return iterable<string,array{string,string,bool,bool}> */
    public static function matching(): iterable
    {
        yield 'deepseek claims its prefix' => ['deepseek', 'deepseek-chat', true, false];
        yield 'deepseek ignores others' => ['deepseek', 'gpt-5', false, false];
        yield 'groq never claims bare names' => ['groq', 'llama-3.3-70b-versatile', false, false];
        yield 'openrouter never claims bare names' => ['openrouter', 'openai/gpt-4o', false, false];
        yield 'xai claims grok' => ['xai', 'grok-4', true, false];
        yield 'huggingface claims org/model' => ['huggingface', 'meta-llama/Llama-3.3-70B', true, false];
        yield 'huggingface ignores tagged names' => ['huggingface', 'llama3.2:3b', false, false];
    }

    #[DataProvider('matching')]
    public function testMatches(string $provider, string $model, bool $expected): void
    {
        $providers = [
            'deepseek' => new DeepSeekProvider(),
            'groq' => new GroqProvider(),
            'openrouter' => new OpenRouterProvider(),
            'xai' => new XAiProvider(),
            'huggingface' => new HuggingFaceProvider(),
        ];

        self::assertSame($expected, $providers[$provider]->matches($model));
        self::assertSame($provider, $providers[$provider]->id());
    }

    public function testDeepSeekCapabilities(): void
    {
        $client = DeepSeekClient::create('deepseek-chat', $this->config());

        // DeepSeek has no embeddings endpoint, so the client has no embed method.
        self::assertInstanceOf(Chatter::class, $client);
        self::assertNotContains(Embedder::class, class_implements($client));
        self::assertSame('deepseek', $client->provider());
        self::assertSame('deepseek-chat', $client->model());
    }

    public function testDeepSeekReasonerRejectsToolsBeforeSending(): void
    {
        $client = DeepSeekClient::create('deepseek-reasoner', $this->config());

        try {
            $client->chat(new Request([Message::userText('x')], tools: [new Tool('weather')]));
            self::fail('expected tools to be rejected');
        } catch (UnsupportedException $e) {
            self::assertStringContainsString('tools with deepseek-reasoner', $e->getMessage());
        }
        self::assertCount(0, $this->transport->requests());
    }

    public function testDeepSeekSendsNoReasoningSwitch(): void
    {
        $this->queueChat();
        DeepSeekClient::create('deepseek-reasoner', $this->config())->chat(new Request(
            messages: [Message::userText('x')],
            reasoning: new ReasoningConfig(effort: 'high'),
        ));

        self::assertArrayNotHasKey('reasoning_effort', $this->transport->lastBody());
        self::assertSame('https://api.deepseek.com/chat/completions', $this->transport->lastRequest()->url);
    }

    public function testGroqUsesItsOwnBaseUrl(): void
    {
        $this->queueChat();
        GroqClient::create('llama-3.3-70b-versatile', $this->config())->chat(Request::prompt('x'));

        self::assertSame(
            'https://api.groq.com/openai/v1/chat/completions',
            $this->transport->lastRequest()->url,
        );
    }

    public function testXAiStreamsUsage(): void
    {
        $this->transport->pushSse("data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\ndata: [DONE]\n\n");
        iterator_to_array(XAiClient::create('grok-4', $this->config())->stream(Request::prompt('x')), false);

        self::assertSame(['include_usage' => true], $this->transport->lastBody()['stream_options']);
    }

    public function testOpenRouterReasoningAndAttributionHeaders(): void
    {
        $this->queueChat();
        $config = $this->config()
            ->withHeader(OpenRouterClient::HEADER_REFERER, 'https://app.test')
            ->withHeader(OpenRouterClient::HEADER_TITLE, 'demo');

        OpenRouterClient::create('openai/gpt-4o', $config)->chat(new Request(
            messages: [Message::userText('x')],
            reasoning: new ReasoningConfig(effort: 'high', budgetTokens: 2048),
        ));

        self::assertSame(
            ['effort' => 'high', 'max_tokens' => 2048],
            $this->transport->lastBody()['reasoning'],
        );
        $headers = $this->transport->lastRequest()->headers;
        self::assertSame(['https://app.test'], $headers['HTTP-Referer']);
        self::assertSame(['demo'], $headers['X-Title']);
    }

    public function testOpenRouterEmbeds(): void
    {
        $this->transport->pushJson(['data' => [['index' => 0, 'embedding' => [0.5]]]]);

        $response = OpenRouterClient::create('openai/text-embedding-3-small', $this->config())
            ->embed(new EmbedRequest(['hi']));

        self::assertSame([[0.5]], $response->embeddings);
        self::assertSame('https://openrouter.ai/api/v1/embeddings', $this->transport->lastRequest()->url);
    }

    public function testHuggingFaceChatUsesTheRouter(): void
    {
        $this->queueChat();
        HuggingFaceClient::create('meta-llama/Llama-3.3-70B-Instruct', $this->config())->chat(Request::prompt('x'));

        self::assertSame(
            'https://router.huggingface.co/v1/chat/completions',
            $this->transport->lastRequest()->url,
        );
    }

    public function testHuggingFaceEmbedsSentenceVectors(): void
    {
        $transport = new MockTransport();
        $transport->push('[[0.1,0.2],[0.3,0.4]]');
        $config = (new Config())->withTransport($transport)->withApiKey('hf');

        $response = HuggingFaceClient::create('sentence-transformers/all-MiniLM-L6-v2', $config)
            ->embed(new EmbedRequest(['first', 'second']));

        self::assertSame([[0.1, 0.2], [0.3, 0.4]], $response->embeddings);
        self::assertSame(
            'https://router.huggingface.co/hf-inference/models/'
            . 'sentence-transformers/all-MiniLM-L6-v2/pipeline/feature-extraction',
            $transport->lastRequest()->url,
        );
        self::assertSame(['inputs' => ['first', 'second']], $transport->lastBody());
    }

    public function testHuggingFaceMeanPoolsTokenVectorsForOneInput(): void
    {
        $this->transport->push('[[0.0,2.0],[2.0,4.0]]');

        $response = HuggingFaceClient::create('bert-base/uncased', $this->config())
            ->embed(new EmbedRequest(['only']));

        self::assertSame([[1.0, 3.0]], $response->embeddings);
    }

    public function testHuggingFaceMeanPoolsThreeDimensionalOutput(): void
    {
        $this->transport->push('[[[0.0,2.0],[2.0,4.0]],[[1.0,1.0],[3.0,3.0]]]');

        $response = HuggingFaceClient::create('bert-base/uncased', $this->config())
            ->embed(new EmbedRequest(['a', 'b']));

        self::assertSame([[1.0, 3.0], [2.0, 2.0]], $response->embeddings);
    }
}
