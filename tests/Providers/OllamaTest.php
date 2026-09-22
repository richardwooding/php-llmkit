<?php

declare(strict_types=1);

namespace LlmKit\Tests\Providers;

use LlmKit\ChunkKind;
use LlmKit\Config;
use LlmKit\EmbedRequest;
use LlmKit\Exception\UnsupportedException;
use LlmKit\FinishReason;
use LlmKit\Http\MockTransport;
use LlmKit\ImagePart;
use LlmKit\Message;
use LlmKit\Providers\Ollama\OllamaClient;
use LlmKit\Providers\Ollama\OllamaProvider;
use LlmKit\ReasoningConfig;
use LlmKit\ReasoningPart;
use LlmKit\Request;
use LlmKit\ResponseFormat;
use LlmKit\Stream;
use LlmKit\TextPart;
use LlmKit\Tool;
use LlmKit\ToolCall;
use LlmKit\ToolChoice;
use LlmKit\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OllamaClient::class)]
#[CoversClass(OllamaProvider::class)]
final class OllamaTest extends TestCase
{
    private MockTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
    }

    private function client(string $model = 'llama3.2:3b', ?Config $config = null): OllamaClient
    {
        return OllamaClient::create($model, ($config ?? new Config())->withTransport($this->transport));
    }

    private function queueChat(): void
    {
        $this->transport->pushJson([
            'model' => 'llama3.2:3b',
            'message' => ['role' => 'assistant', 'content' => 'Hello'],
            'done' => true,
            'done_reason' => 'stop',
            'prompt_eval_count' => 9,
            'eval_count' => 2,
        ]);
    }

    /** @return iterable<string,array{string,bool}> */
    public static function modelNames(): iterable
    {
        yield 'tagged' => ['llama3.2:3b', true];
        yield 'hf.co path' => ['hf.co/bartowski/Llama-3.2', true];
        yield 'bare name' => ['llama3.2', false];
        yield 'org model' => ['meta-llama/Llama-3.3', false];
    }

    #[DataProvider('modelNames')]
    public function testMatches(string $model, bool $expected): void
    {
        self::assertSame($expected, (new OllamaProvider())->matches($model));
    }

    public function testNoApiKeyIsNeeded(): void
    {
        $this->queueChat();
        $response = $this->client()->chat(Request::prompt('Hi', 'Be brief.'));

        self::assertSame('Hello', $response->text());
        self::assertSame('http://localhost:11434/api/chat', $this->transport->lastRequest()->url);
        self::assertArrayNotHasKey('Authorization', $this->transport->lastRequest()->headers);
        self::assertSame(11, $response->usage->totalTokens);
    }

    public function testHostIsNormalised(): void
    {
        $this->queueChat();
        $config = (new Config())->withBaseUrl('gpu-box:11434/');
        $this->client('llama3.2:3b', $config)->chat(Request::prompt('x'));

        self::assertSame('http://gpu-box:11434/api/chat', $this->transport->lastRequest()->url);
    }

    public function testOptionsKeepAliveAndFormat(): void
    {
        $this->queueChat();
        $config = (new Config())->withValue(OllamaClient::OPTION_KEEP_ALIVE, '5m');
        $this->client('llama3.2:3b', $config)->chat(new Request(
            messages: [Message::userText('x')],
            maxTokens: 64,
            temperature: 0.1,
            topP: 0.8,
            stop: ['END'],
            seed: 7,
            format: ResponseFormat::json(),
            reasoning: new ReasoningConfig(),
        ));

        $body = $this->transport->lastBody();
        self::assertSame([
            'num_predict' => 64,
            'temperature' => 0.1,
            'top_p' => 0.8,
            'stop' => ['END'],
            'seed' => 7,
        ], $body['options']);
        self::assertSame('5m', $body['keep_alive']);
        self::assertSame('json', $body['format']);
        self::assertTrue($body['think']);
        self::assertFalse($body['stream']);
    }

    public function testImagesTravelAsBase64AndUrlsAreRejected(): void
    {
        $this->queueChat();
        $this->client()->chat(new Request([
            Message::user(new TextPart('what is this'), new ImagePart('bytes', 'image/png')),
        ]));

        self::assertSame([
            'role' => 'user',
            'content' => 'what is this',
            'images' => [base64_encode('bytes')],
        ], $this->transport->lastBody()['messages'][0]);

        $this->expectException(UnsupportedException::class);
        $this->client()->chat(new Request([Message::user(ImagePart::url('https://example.test/a.png'))]));
    }

    public function testToolCallsAndResults(): void
    {
        $this->queueChat();
        $this->client()->chat(new Request(
            messages: [
                Message::userText('Weather?'),
                Message::assistant(
                    new ReasoningPart('thinking'),
                    new ToolCall('call_1', 'weather', '{"city":"CPT"}'),
                ),
                Message::tool(ToolResult::text('call_1', 'weather', 'sunny')),
            ],
            tools: [new Tool('weather', 'Current weather', ['type' => 'object'])],
        ));

        $body = $this->transport->lastBody();
        self::assertSame([[
            'type' => 'function',
            'function' => ['name' => 'weather', 'description' => 'Current weather', 'parameters' => ['type' => 'object']],
        ]], $body['tools']);
        self::assertSame([
            'role' => 'assistant',
            'content' => '',
            'thinking' => 'thinking',
            'tool_calls' => [['function' => ['name' => 'weather', 'arguments' => ['city' => 'CPT']]]],
        ], $body['messages'][1]);
        self::assertSame(
            ['role' => 'tool', 'content' => 'sunny', 'tool_name' => 'weather'],
            $body['messages'][2],
        );
    }

    public function testToolChoiceNoneDropsTools(): void
    {
        $this->queueChat();
        $this->client()->chat(new Request(
            messages: [Message::userText('x')],
            tools: [new Tool('weather')],
            toolChoice: ToolChoice::none(),
        ));

        self::assertArrayNotHasKey('tools', $this->transport->lastBody());
    }

    public function testResponseWithSynthesisedCallIds(): void
    {
        $this->transport->pushJson([
            'model' => 'llama3.2:3b',
            'message' => [
                'role' => 'assistant',
                'thinking' => 'let me look',
                'content' => 'checking',
                'tool_calls' => [
                    ['function' => ['name' => 'weather', 'arguments' => ['city' => 'CPT']]],
                    ['function' => ['name' => 'clock', 'arguments' => []]],
                ],
            ],
            'done' => true,
            'done_reason' => 'stop',
        ]);

        $response = $this->client()->chat(Request::prompt('x'));

        self::assertEquals([
            new ReasoningPart('let me look'),
            new TextPart('checking'),
            new ToolCall('call_1', 'weather', '{"city":"CPT"}'),
            new ToolCall('call_2', 'clock', '{}'),
        ], $response->message->parts);
        self::assertSame(FinishReason::ToolCalls, $response->finishReason);
    }

    public function testStreamOverNdjson(): void
    {
        $this->transport->push(implode('', [
            json_encode(['message' => ['thinking' => 'hmm']]) . "\n",
            json_encode(['message' => ['content' => 'Hel']]) . "\n",
            json_encode(['message' => ['content' => 'lo']]) . "\n",
            json_encode(['message' => [
                'tool_calls' => [['function' => ['name' => 'weather', 'arguments' => ['city' => 'CPT']]]],
            ]]) . "\n",
            json_encode([
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 5,
                'eval_count' => 7,
                'message' => ['content' => ''],
            ]) . "\n",
        ]), chunk: 11);

        $chunks = [];
        foreach ($this->client()->stream(Request::prompt('x')) as $chunk) {
            $chunks[] = $chunk->withoutRaw();
        }

        self::assertTrue($this->transport->lastBody()['stream']);
        self::assertSame(ChunkKind::Finish, end($chunks)->kind);

        $collected = Stream::collect($chunks);
        self::assertEquals([
            new ReasoningPart('hmm'),
            new TextPart('Hello'),
            new ToolCall('call_1', 'weather', '{"city":"CPT"}'),
        ], $collected->message->parts);
        self::assertSame(FinishReason::ToolCalls, $collected->finishReason);
        self::assertSame(12, $collected->usage->totalTokens);
    }

    public function testEmbed(): void
    {
        $this->transport->pushJson([
            'model' => 'nomic-embed-text',
            'embeddings' => [[0.1, 0.2], [0.3, 0.4]],
            'prompt_eval_count' => 8,
        ]);

        $response = $this->client('nomic-embed-text:latest')->embed(new EmbedRequest(['a', 'b']));

        self::assertSame([[0.1, 0.2], [0.3, 0.4]], $response->embeddings);
        self::assertSame('nomic-embed-text', $response->model);
        self::assertSame(8, $response->usage->totalTokens);
        self::assertSame('http://localhost:11434/api/embed', $this->transport->lastRequest()->url);
    }
}
