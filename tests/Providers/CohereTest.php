<?php

declare(strict_types=1);

namespace LlmKit\Tests\Providers;

use LlmKit\ChunkKind;
use LlmKit\Config;
use LlmKit\Embedder;
use LlmKit\EmbedInputType;
use LlmKit\EmbedRequest;
use LlmKit\Exception\MissingApiKeyException;
use LlmKit\Exception\UnsupportedException;
use LlmKit\FinishReason;
use LlmKit\Http\MockTransport;
use LlmKit\ImagePart;
use LlmKit\Message;
use LlmKit\Providers\Cohere\CohereClient;
use LlmKit\Providers\Cohere\CohereProvider;
use LlmKit\ReasoningConfig;
use LlmKit\ReasoningPart;
use LlmKit\Request;
use LlmKit\Reranker;
use LlmKit\RerankRequest;
use LlmKit\Stream;
use LlmKit\TextPart;
use LlmKit\Tool;
use LlmKit\ToolCall;
use LlmKit\ToolChoice;
use LlmKit\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CohereClient::class)]
#[CoversClass(CohereProvider::class)]
final class CohereTest extends TestCase
{
    private MockTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
    }

    private function client(string $model = 'command-a-03-2025'): CohereClient
    {
        return CohereClient::create(
            $model,
            (new Config())->withTransport($this->transport)->withApiKey('co-test'),
        );
    }

    private function queueChat(): void
    {
        $this->transport->pushJson([
            'id' => 'c-1',
            'finish_reason' => 'COMPLETE',
            'message' => ['content' => [['type' => 'text', 'text' => 'Hello']]],
            'usage' => ['tokens' => ['input_tokens' => 12.0, 'output_tokens' => 3.0]],
        ]);
    }

    /** @return iterable<string,array{string,bool}> */
    public static function modelNames(): iterable
    {
        yield 'command' => ['command-a-03-2025', true];
        yield 'embed' => ['embed-v4.0', true];
        yield 'rerank' => ['rerank-v3.5', true];
        yield 'aya' => ['aya-expanse-8b', true];
        yield 'other' => ['gpt-5', false];
    }

    #[DataProvider('modelNames')]
    public function testMatches(string $model, bool $expected): void
    {
        self::assertSame($expected, (new CohereProvider())->matches($model));
    }

    public function testMissingKeyFailsBeforeAnyCall(): void
    {
        $this->expectException(MissingApiKeyException::class);
        CohereClient::create('command-a-03-2025', (new Config())->withTransport($this->transport));
    }

    public function testCapabilities(): void
    {
        $client = $this->client();

        self::assertInstanceOf(Embedder::class, $client);
        self::assertInstanceOf(Reranker::class, $client);
    }

    public function testChatBodyAndResponse(): void
    {
        $this->queueChat();
        $response = $this->client()->chat(new Request(
            messages: [Message::system('Be brief.'), Message::userText('Hi')],
            maxTokens: 100,
            topP: 0.7,
            seed: 3,
        ));

        self::assertSame([
            'model' => 'command-a-03-2025',
            'messages' => [
                ['role' => 'system', 'content' => 'Be brief.'],
                ['role' => 'user', 'content' => 'Hi'],
            ],
            'max_tokens' => 100,
            'p' => 0.7,
            'seed' => 3,
        ], $this->transport->lastBody());

        self::assertSame('Hello', $response->text());
        self::assertSame('c-1', $response->id);
        self::assertSame(12, $response->usage->inputTokens);
        self::assertSame(15, $response->usage->totalTokens);
        self::assertSame('https://api.cohere.com/v2/chat', $this->transport->lastRequest()->url);
        self::assertSame(['Bearer co-test'], $this->transport->lastRequest()->headers['Authorization']);
    }

    public function testToolPlanAndThinkingBecomeReasoning(): void
    {
        $this->transport->pushJson([
            'id' => 'c-2',
            'finish_reason' => 'TOOL_CALL',
            'message' => [
                'tool_plan' => 'I will check the weather',
                'content' => [['type' => 'thinking', 'thinking' => 'deep thoughts']],
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'weather', 'arguments' => '{"city":"CPT"}'],
                ]],
            ],
            'usage' => ['billed_units' => ['input_tokens' => 5.0, 'output_tokens' => 1.0], 'cached_tokens' => 4.0],
        ]);

        $response = $this->client()->chat(Request::prompt('Weather?'));

        self::assertEquals([
            new ReasoningPart('I will check the weather'),
            new ReasoningPart('deep thoughts'),
            new ToolCall('call_1', 'weather', '{"city":"CPT"}'),
        ], $response->message->parts);
        self::assertSame(FinishReason::ToolCalls, $response->finishReason);
        self::assertSame(4, $response->usage->cachedInputTokens);
        self::assertSame(5, $response->usage->inputTokens);
    }

    public function testToolCallsAndDocumentShapedResults(): void
    {
        $this->queueChat();
        $this->client()->chat(new Request(
            messages: [
                Message::userText('Weather?'),
                Message::assistant(new ToolCall('call_1', 'weather', '{"city":"CPT"}')),
                Message::tool(ToolResult::text('call_1', 'weather', 'sunny')),
            ],
            tools: [new Tool('weather', 'Current weather', ['type' => 'object'])],
            toolChoice: ToolChoice::required(),
            reasoning: new ReasoningConfig(budgetTokens: 2048),
        ));

        $body = $this->transport->lastBody();
        self::assertSame('REQUIRED', $body['tool_choice']);
        self::assertSame(['type' => 'enabled', 'token_budget' => 2048], $body['thinking']);
        self::assertSame([
            'role' => 'assistant',
            'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'weather', 'arguments' => '{"city":"CPT"}'],
            ]],
        ], $body['messages'][1]);
        self::assertSame([
            'role' => 'tool',
            'tool_call_id' => 'call_1',
            'content' => [['type' => 'document', 'document' => ['data' => ['text' => 'sunny']]]],
        ], $body['messages'][2]);
    }

    public function testNamedToolChoiceIsUnsupported(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->client()->chat(new Request(
            messages: [Message::userText('x')],
            tools: [new Tool('weather')],
            toolChoice: ToolChoice::named('weather'),
        ));
    }

    public function testImagesInUserContent(): void
    {
        $this->queueChat();
        $this->client()->chat(new Request([
            Message::user(new TextPart('look'), new ImagePart('bytes', 'image/png')),
        ]));

        self::assertSame([
            ['type' => 'text', 'text' => 'look'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('bytes')]],
        ], $this->transport->lastBody()['messages'][0]['content']);
    }

    public function testStream(): void
    {
        $this->transport->pushSse(implode('', [
            "event: content-delta\ndata: {\"type\":\"content-delta\",\"index\":0,"
                . "\"delta\":{\"message\":{\"content\":{\"type\":\"text\",\"text\":\"Hel\"}}}}\n\n",
            "event: content-delta\ndata: {\"type\":\"content-delta\",\"index\":0,"
                . "\"delta\":{\"message\":{\"content\":{\"type\":\"text\",\"text\":\"lo\"}}}}\n\n",
            "event: tool-plan-delta\ndata: {\"type\":\"tool-plan-delta\","
                . "\"delta\":{\"message\":{\"tool_plan\":\"planning\"}}}\n\n",
            "event: tool-call-start\ndata: {\"type\":\"tool-call-start\",\"index\":0,"
                . "\"delta\":{\"message\":{\"tool_calls\":{\"id\":\"call_1\","
                . "\"function\":{\"name\":\"weather\",\"arguments\":\"\"}}}}}\n\n",
            "event: tool-call-delta\ndata: {\"type\":\"tool-call-delta\",\"index\":0,"
                . "\"delta\":{\"message\":{\"tool_calls\":{\"function\":{\"arguments\":\"{\\\"city\\\":\\\"CPT\\\"}\"}}}}}\n\n",
            "event: message-end\ndata: {\"type\":\"message-end\",\"delta\":{\"finish_reason\":\"TOOL_CALL\","
                . "\"usage\":{\"tokens\":{\"input_tokens\":9.0,\"output_tokens\":4.0}}}}\n\n",
        ]));

        $chunks = [];
        foreach ($this->client()->stream(Request::prompt('Weather?')) as $chunk) {
            $chunks[] = $chunk->withoutRaw();
        }

        self::assertTrue($this->transport->lastBody()['stream']);
        self::assertSame(ChunkKind::Finish, end($chunks)->kind);

        $collected = Stream::collect($chunks);
        self::assertEquals([
            new ReasoningPart('planning'),
            new TextPart('Hello'),
            new ToolCall('call_1', 'weather', '{"city":"CPT"}'),
        ], $collected->message->parts);
        self::assertSame(FinishReason::ToolCalls, $collected->finishReason);
        self::assertSame(13, $collected->usage->totalTokens);
    }

    public function testStreamWithoutMessageEndFinishesAsOther(): void
    {
        $this->transport->pushSse(
            "event: content-delta\ndata: {\"type\":\"content-delta\","
            . "\"delta\":{\"message\":{\"content\":{\"type\":\"text\",\"text\":\"hi\"}}}}\n\n",
        );

        $chunks = iterator_to_array($this->client()->stream(Request::prompt('x')), false);

        self::assertCount(2, $chunks);
        self::assertSame(FinishReason::Other, $chunks[1]->finishReason);
    }

    public function testEmbed(): void
    {
        $this->transport->pushJson([
            'id' => 'e-1',
            'embeddings' => ['float' => [[0.1, 0.2]]],
            'meta' => ['billed_units' => ['input_tokens' => 6.0]],
        ]);

        $response = $this->client('embed-v4.0')->embed(new EmbedRequest(
            ['hello'],
            dimensions: 256,
            inputType: EmbedInputType::Query,
        ));

        self::assertSame([[0.1, 0.2]], $response->embeddings);
        self::assertSame(6, $response->usage->inputTokens);
        self::assertSame([
            'model' => 'embed-v4.0',
            'texts' => ['hello'],
            'input_type' => 'search_query',
            'embedding_types' => ['float'],
            'output_dimension' => 256,
            'truncate' => 'END',
        ], $this->transport->lastBody());
    }

    public function testRerank(): void
    {
        $this->transport->pushJson([
            'id' => 'r-1',
            'results' => [
                ['index' => 2, 'relevance_score' => 0.9],
                ['index' => 0, 'relevance_score' => 0.4],
            ],
        ]);

        $response = $this->client('rerank-v3.5')->rerank(new RerankRequest(
            'go iterators',
            ['a', 'b', 'c'],
            topN: 2,
        ));

        self::assertSame([[2, 0.9], [0, 0.4]], array_map(
            static fn($r): array => [$r->index, $r->score],
            $response->results,
        ));
        self::assertSame([
            'model' => 'rerank-v3.5',
            'query' => 'go iterators',
            'documents' => ['a', 'b', 'c'],
            'top_n' => 2,
        ], $this->transport->lastBody());
        self::assertSame('https://api.cohere.com/v2/rerank', $this->transport->lastRequest()->url);
    }
}
