<?php

declare(strict_types=1);

namespace LlmKit\Tests\Providers;

use LlmKit\AudioPart;
use LlmKit\ChunkKind;
use LlmKit\Config;
use LlmKit\EmbedRequest;
use LlmKit\Exception\ApiException;
use LlmKit\Exception\MissingApiKeyException;
use LlmKit\Exception\UnsupportedException;
use LlmKit\FilePart;
use LlmKit\FinishReason;
use LlmKit\Http\MockTransport;
use LlmKit\ImagePart;
use LlmKit\Message;
use LlmKit\Providers\OpenAi\OpenAiClient;
use LlmKit\Providers\OpenAi\OpenAiProvider;
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

#[CoversClass(OpenAiClient::class)]
#[CoversClass(OpenAiProvider::class)]
final class OpenAiTest extends TestCase
{
    private MockTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
    }

    private function client(string $model = 'gpt-5'): OpenAiClient
    {
        return OpenAiClient::create(
            $model,
            (new Config())->withTransport($this->transport)->withApiKey('sk-test'),
        );
    }

    private function queueText(string $text = 'Hello'): void
    {
        $this->transport->pushJson([
            'id' => 'resp_1',
            'model' => 'gpt-5-2026-01-01',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => $text]],
            ]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 4, 'total_tokens' => 16],
        ]);
    }

    /** @return iterable<string,array{string,bool}> */
    public static function modelNames(): iterable
    {
        yield 'gpt' => ['gpt-5', true];
        yield 'chatgpt' => ['chatgpt-4o-latest', true];
        yield 'o-series' => ['o3-mini', true];
        yield 'embeddings' => ['text-embedding-3-large', true];
        yield 'claude' => ['claude-sonnet-4-5', false];
        yield 'ollama tag' => ['llama3.2:3b', false];
    }

    #[DataProvider('modelNames')]
    public function testMatches(string $model, bool $expected): void
    {
        self::assertSame($expected, (new OpenAiProvider())->matches($model));
    }

    public function testMissingKeyFailsBeforeAnyCall(): void
    {
        $this->expectException(MissingApiKeyException::class);
        OpenAiClient::create('gpt-5', (new Config())->withTransport($this->transport));
    }

    public function testSystemMessagesBecomeInstructions(): void
    {
        $this->queueText();
        $response = $this->client()->chat(new Request(
            messages: [
                Message::system('Be brief.'),
                Message::system('Be kind.'),
                Message::userText('Hi'),
            ],
            maxTokens: 256,
            temperature: 0.2,
        ));

        self::assertSame([
            'model' => 'gpt-5',
            'instructions' => "Be brief.\n\nBe kind.",
            'input' => [[
                'type' => 'message',
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => 'Hi']],
            ]],
            'max_output_tokens' => 256,
            'temperature' => 0.2,
        ], $this->transport->lastBody());

        self::assertSame('Hello', $response->text());
        self::assertSame('resp_1', $response->id);
        self::assertSame('gpt-5-2026-01-01', $response->model);
        self::assertSame(16, $response->usage->totalTokens);
        self::assertSame('https://api.openai.com/v1/responses', $this->transport->lastRequest()->url);
    }

    public function testStopAndSeedAreIgnored(): void
    {
        $this->queueText();
        $this->client()->chat(new Request(
            messages: [Message::userText('x')],
            stop: ['END'],
            seed: 7,
        ));

        $body = $this->transport->lastBody();
        self::assertArrayNotHasKey('stop', $body);
        self::assertArrayNotHasKey('seed', $body);
    }

    public function testToolsAreFlatAndCallsUseCallId(): void
    {
        $this->queueText();
        $this->client()->chat(new Request(
            messages: [Message::userText('Weather?')],
            tools: [new Tool('weather', 'Current weather', ['type' => 'object'], strict: true)],
            toolChoice: ToolChoice::named('weather'),
        ));

        $body = $this->transport->lastBody();
        self::assertSame([[
            'type' => 'function',
            'name' => 'weather',
            'description' => 'Current weather',
            'parameters' => ['type' => 'object'],
            'strict' => true,
        ]], $body['tools']);
        self::assertSame(['type' => 'function', 'name' => 'weather'], $body['tool_choice']);
    }

    public function testConversationWithReasoningToolCallAndResult(): void
    {
        $this->queueText();
        $this->client()->chat(new Request([
            Message::userText('Weather?'),
            Message::assistant(
                new ReasoningPart('summary', 'rs_1', 'encrypted-blob'),
                new TextPart('Let me check.'),
                new ToolCall('call_1', 'weather', '{"city":"Cape Town"}'),
            ),
            Message::tool(ToolResult::text('call_1', 'weather', 'sunny')),
        ]));

        self::assertSame([
            [
                'type' => 'message',
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => 'Weather?']],
            ],
            [
                'type' => 'reasoning',
                'id' => 'rs_1',
                'encrypted_content' => 'encrypted-blob',
                'summary' => [],
            ],
            [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Let me check.']],
            ],
            [
                'type' => 'function_call',
                'call_id' => 'call_1',
                'name' => 'weather',
                'arguments' => '{"city":"Cape Town"}',
            ],
            ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => 'sunny'],
        ], $this->transport->lastBody()['input']);
    }

    public function testUnsignedReasoningIsNotReplayed(): void
    {
        $this->queueText();
        $this->client()->chat(new Request([
            Message::assistant(new ReasoningPart('just a summary'), new TextPart('hi')),
        ]));

        self::assertSame([[
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => 'hi']],
        ]], $this->transport->lastBody()['input']);
    }

    public function testImagesAndFilesAreMapped(): void
    {
        $this->queueText();
        $this->client()->chat(new Request([Message::user(
            new TextPart('look'),
            new ImagePart('bytes', 'image/png', detail: 'high'),
            ImagePart::url('https://example.test/a.png'),
            new FilePart('%PDF', 'application/pdf', name: 'report.pdf'),
            FilePart::url('https://example.test/a.pdf'),
        )]));

        self::assertSame([
            ['type' => 'input_text', 'text' => 'look'],
            [
                'type' => 'input_image',
                'image_url' => 'data:image/png;base64,' . base64_encode('bytes'),
                'detail' => 'high',
            ],
            ['type' => 'input_image', 'image_url' => 'https://example.test/a.png'],
            [
                'type' => 'input_file',
                'filename' => 'report.pdf',
                'file_data' => 'data:application/pdf;base64,' . base64_encode('%PDF'),
            ],
            ['type' => 'input_file', 'file_url' => 'https://example.test/a.pdf'],
        ], $this->transport->lastBody()['input'][0]['content']);
    }

    public function testAudioIsRejectedBeforeSending(): void
    {
        try {
            $this->client()->chat(new Request([Message::user(new AudioPart('sound', 'audio/mpeg'))]));
            self::fail('expected audio to be rejected');
        } catch (UnsupportedException $e) {
            self::assertStringContainsString('audio input', $e->getMessage());
        }
        self::assertCount(0, $this->transport->requests());
    }

    public function testReasoningConfigAsksForEncryptedContent(): void
    {
        $this->queueText();
        $this->client()->chat(new Request(
            messages: [Message::userText('x')],
            reasoning: new ReasoningConfig(effort: 'high', summary: 'summarized'),
        ));

        $body = $this->transport->lastBody();
        self::assertSame(['effort' => 'high', 'summary' => 'auto'], $body['reasoning']);
        self::assertSame(['reasoning.encrypted_content'], $body['include']);
    }

    public function testJsonSchemaFormat(): void
    {
        $this->queueText();
        $this->client()->chat(new Request(
            messages: [Message::userText('x')],
            format: ResponseFormat::jsonSchema('answer', ['type' => 'object']),
        ));

        self::assertSame(['format' => [
            'type' => 'json_schema',
            'name' => 'answer',
            'schema' => ['type' => 'object'],
            'strict' => true,
        ]], $this->transport->lastBody()['text']);
    }

    public function testResponseWithReasoningToolCallAndRefusal(): void
    {
        $this->transport->pushJson([
            'id' => 'resp_2',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'reasoning',
                    'id' => 'rs_9',
                    'encrypted_content' => 'blob',
                    'summary' => [
                        ['type' => 'summary_text', 'text' => 'first'],
                        ['type' => 'summary_text', 'text' => 'second'],
                    ],
                ],
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'output_text', 'text' => 'Here you go'],
                        ['type' => 'refusal', 'refusal' => 'but not that part'],
                    ],
                ],
                [
                    'type' => 'function_call',
                    'call_id' => 'call_7',
                    'name' => 'weather',
                    'arguments' => '{"city":"CPT"}',
                ],
            ],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 40,
                'input_tokens_details' => ['cached_tokens' => 64],
                'output_tokens_details' => ['reasoning_tokens' => 30],
            ],
        ]);

        $response = $this->client()->chat(Request::prompt('x'));

        self::assertEquals([
            new ReasoningPart("first\n\nsecond", 'rs_9', 'blob'),
            new TextPart('Here you go'),
            new TextPart('but not that part'),
            new ToolCall('call_7', 'weather', '{"city":"CPT"}'),
        ], $response->message->parts);
        self::assertSame(FinishReason::ToolCalls, $response->finishReason);
        self::assertSame(140, $response->usage->totalTokens);
        self::assertSame(64, $response->usage->cachedInputTokens);
        self::assertSame(30, $response->usage->reasoningTokens);
    }

    /** @return iterable<string,array{string,FinishReason}> */
    public static function incompleteReasons(): iterable
    {
        yield 'max output tokens' => ['max_output_tokens', FinishReason::Length];
        yield 'content filter' => ['content_filter', FinishReason::ContentFilter];
        yield 'anything else' => ['mystery', FinishReason::Other];
    }

    #[DataProvider('incompleteReasons')]
    public function testIncompleteResponses(string $reason, FinishReason $expected): void
    {
        $this->transport->pushJson([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => $reason],
            'output' => [],
        ]);

        self::assertSame($expected, $this->client()->chat(Request::prompt('x'))->finishReason);
    }

    public function testFailedResponseBecomesAnApiException(): void
    {
        $this->transport->pushJson([
            'status' => 'failed',
            'error' => ['code' => 'server_error', 'message' => 'model exploded'],
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('openai: [server_error]: model exploded');
        $this->client()->chat(Request::prompt('x'));
    }

    public function testStreamEventsBecomeChunks(): void
    {
        $this->transport->pushSse(implode('', [
            "event: response.created\ndata: {\"type\":\"response.created\"}\n\n",
            "data: {\"type\":\"response.reasoning_summary_text.delta\",\"output_index\":0,\"delta\":\"thinking\"}\n\n",
            "data: {\"type\":\"response.output_item.done\",\"output_index\":0,"
                . "\"item\":{\"type\":\"reasoning\",\"id\":\"rs_1\",\"encrypted_content\":\"blob\"}}\n\n",
            "data: {\"type\":\"response.output_text.delta\",\"output_index\":1,\"delta\":\"Hel\"}\n\n",
            "data: {\"type\":\"response.output_text.delta\",\"output_index\":1,\"delta\":\"lo\"}\n\n",
            "data: {\"type\":\"response.output_item.added\",\"output_index\":2,"
                . "\"item\":{\"type\":\"function_call\",\"call_id\":\"call_1\",\"name\":\"weather\"}}\n\n",
            "data: {\"type\":\"response.function_call_arguments.delta\",\"output_index\":2,"
                . "\"delta\":\"{\\\"city\\\":\\\"CPT\\\"}\"}\n\n",
            "data: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\","
                . "\"usage\":{\"input_tokens\":5,\"output_tokens\":7,\"total_tokens\":12}}}\n\n",
        ]));

        $chunks = [];
        foreach ($this->client()->stream(Request::prompt('Weather?')) as $chunk) {
            $chunks[] = $chunk->withoutRaw();
        }

        self::assertTrue($this->transport->lastBody()['stream']);
        self::assertSame([
            ChunkKind::Reasoning,
            ChunkKind::Reasoning,
            ChunkKind::Text,
            ChunkKind::Text,
            ChunkKind::ToolCall,
            ChunkKind::ToolCall,
            ChunkKind::Finish,
        ], array_map(static fn($c): ChunkKind => $c->kind, $chunks));

        $collected = Stream::collect($chunks);
        self::assertEquals([
            new ReasoningPart('thinking', 'rs_1', 'blob'),
            new TextPart('Hello'),
            new ToolCall('call_1', 'weather', '{"city":"CPT"}'),
        ], $collected->message->parts);
        self::assertSame(FinishReason::ToolCalls, $collected->finishReason);
        self::assertSame(12, $collected->usage->totalTokens);
    }

    public function testStreamStopsAfterTheTerminalEvent(): void
    {
        $this->transport->pushSse(implode('', [
            "data: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\"}}\n\n",
            "data: {\"type\":\"response.output_text.delta\",\"delta\":\"never\"}\n\n",
        ]));

        $chunks = iterator_to_array($this->client()->stream(Request::prompt('x')), false);

        self::assertCount(1, $chunks);
        self::assertSame(ChunkKind::Finish, $chunks[0]->kind);
    }

    public function testStreamWithoutTerminalEventStillFinishes(): void
    {
        $this->transport->pushSse("data: {\"type\":\"response.output_text.delta\",\"delta\":\"hi\"}\n\n");

        $chunks = iterator_to_array($this->client()->stream(Request::prompt('x')), false);

        self::assertCount(2, $chunks);
        self::assertSame(FinishReason::Other, $chunks[1]->finishReason);
    }

    public function testStreamErrorEventBecomesAnApiException(): void
    {
        $this->transport->pushSse(
            "data: {\"type\":\"error\",\"code\":\"rate_limit_exceeded\",\"message\":\"slow down\"}\n\n",
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('slow down');
        iterator_to_array($this->client()->stream(Request::prompt('x')), false);
    }

    public function testEmbedUsesTheEmbeddingsEndpoint(): void
    {
        $this->transport->pushJson([
            'model' => 'text-embedding-3-small',
            'data' => [['index' => 0, 'embedding' => [0.1, 0.2]]],
            'usage' => ['prompt_tokens' => 3, 'total_tokens' => 3],
        ]);

        $response = $this->client('text-embedding-3-small')->embed(new EmbedRequest(['hi'], dimensions: 2));

        self::assertSame([[0.1, 0.2]], $response->embeddings);
        self::assertSame('https://api.openai.com/v1/embeddings', $this->transport->lastRequest()->url);
        self::assertSame(2, $this->transport->lastBody()['dimensions']);
    }

    public function testOrganizationAndProjectHeaders(): void
    {
        $this->queueText();
        $config = (new Config())
            ->withTransport($this->transport)
            ->withApiKey('sk-test')
            ->withHeader(OpenAiClient::HEADER_ORGANIZATION, 'org-1')
            ->withHeader(OpenAiClient::HEADER_PROJECT, 'proj-1');

        OpenAiClient::create('gpt-5', $config)->chat(Request::prompt('x'));

        $headers = $this->transport->lastRequest()->headers;
        self::assertSame(['org-1'], $headers[OpenAiClient::HEADER_ORGANIZATION]);
        self::assertSame(['proj-1'], $headers[OpenAiClient::HEADER_PROJECT]);
    }
}
