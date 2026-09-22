<?php

declare(strict_types=1);

namespace LlmKit\Tests\Providers;

use LlmKit\AudioPart;
use LlmKit\Chunk;
use LlmKit\ChunkKind;
use LlmKit\Config;
use LlmKit\EmbedRequest;
use LlmKit\Exception\MissingApiKeyException;
use LlmKit\Exception\UnsupportedException;
use LlmKit\FilePart;
use LlmKit\FinishReason;
use LlmKit\Http\MockTransport;
use LlmKit\ImagePart;
use LlmKit\Message;
use LlmKit\Providers\OpenAiCompat\CompatClient;
use LlmKit\Providers\OpenAiCompat\EndpointConfig;
use LlmKit\Providers\OpenAiCompat\Quirks;
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
use PHPUnit\Framework\TestCase;

#[CoversClass(CompatClient::class)]
final class OpenAiCompatTest extends TestCase
{
    private MockTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
    }

    private function client(Quirks $quirks = new Quirks(), string $model = 'test-model'): CompatClient
    {
        $endpoint = new EndpointConfig(
            id: 'compat',
            baseUrl: 'https://api.example.test/v1',
            apiKeyEnv: 'COMPAT_API_KEY',
            headers: ['X-Vendor' => 'compat'],
            quirks: $quirks,
        );
        $config = (new Config())->withTransport($this->transport)->withApiKey('sk-test');

        return CompatClient::create($model, $endpoint, $config);
    }

    /** @param array<string,mixed> $message */
    private function queueChat(array $message = ['role' => 'assistant', 'content' => 'Hello'], string $finish = 'stop'): void
    {
        $this->transport->pushJson([
            'id' => 'chatcmpl-1',
            'model' => 'test-model-0001',
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 3, 'total_tokens' => 14],
        ]);
    }

    public function testChatBodyAndResponse(): void
    {
        $this->queueChat();

        $request = new Request(
            messages: [Message::system('Be brief.'), Message::userText('Hi')],
            maxTokens: 100,
            temperature: 0.5,
            topP: 0.9,
            stop: ['END'],
        );
        $response = $this->client()->chat($request);

        self::assertSame([
            'model' => 'test-model',
            'messages' => [
                ['role' => 'system', 'content' => 'Be brief.'],
                ['role' => 'user', 'content' => 'Hi'],
            ],
            'max_tokens' => 100,
            'temperature' => 0.5,
            'top_p' => 0.9,
            'stop' => ['END'],
        ], $this->transport->lastBody());

        self::assertSame('Hello', $response->text());
        self::assertSame('chatcmpl-1', $response->id);
        self::assertSame('test-model-0001', $response->model);
        self::assertSame(FinishReason::Stop, $response->finishReason);
        self::assertSame(11, $response->usage->inputTokens);
        self::assertSame(14, $response->usage->totalTokens);
        self::assertSame('https://api.example.test/v1/chat/completions', $this->transport->lastRequest()->url);
        self::assertSame(['compat'], $this->transport->lastRequest()->headers['X-Vendor']);
    }

    public function testMissingKeyIsReportedBeforeAnyCall(): void
    {
        $this->expectException(MissingApiKeyException::class);
        CompatClient::create(
            'm',
            new EndpointConfig(id: 'compat', baseUrl: 'https://x.test', apiKeyEnv: 'COMPAT_API_KEY'),
            (new Config())->withTransport($this->transport),
        );
    }

    public function testOptionalKeyEndpointsNeedNoCredential(): void
    {
        $client = CompatClient::create(
            'local',
            new EndpointConfig(id: 'vllm', baseUrl: 'http://gpu-box:8000/v1', keyOptional: true),
            (new Config())->withTransport($this->transport),
        );
        $this->queueChat();
        $client->chat(Request::prompt('hi'));

        self::assertArrayNotHasKey('Authorization', $this->transport->lastRequest()->headers);
    }

    public function testToolsToolChoiceAndSeedQuirks(): void
    {
        $this->queueChat();
        $request = new Request(
            messages: [Message::userText('Weather?')],
            tools: [new Tool('weather', 'Current weather', ['type' => 'object'], strict: true)],
            toolChoice: ToolChoice::named('weather'),
            maxTokens: 50,
            seed: 42,
        );

        $this->client(new Quirks(strict: true, seed: true, maxCompletionTokens: true))->chat($request);

        $body = $this->transport->lastBody();
        self::assertSame([[
            'type' => 'function',
            'function' => [
                'name' => 'weather',
                'description' => 'Current weather',
                'parameters' => ['type' => 'object'],
                'strict' => true,
            ],
        ]], $body['tools']);
        self::assertSame(['type' => 'function', 'function' => ['name' => 'weather']], $body['tool_choice']);
        self::assertSame(50, $body['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $body);
        self::assertSame(42, $body['seed']);
    }

    public function testSeedAndStrictAreDroppedWithoutTheQuirk(): void
    {
        $this->queueChat();
        $this->client()->chat(new Request(
            messages: [Message::userText('x')],
            tools: [new Tool('t', strict: true)],
            toolChoice: ToolChoice::required(),
            seed: 42,
        ));

        $body = $this->transport->lastBody();
        self::assertArrayNotHasKey('seed', $body);
        self::assertArrayNotHasKey('strict', $body['tools'][0]['function']);
        self::assertSame('required', $body['tool_choice']);
    }

    public function testAutoToolChoiceIsOmitted(): void
    {
        $this->queueChat();
        $this->client()->chat(new Request(
            messages: [Message::userText('x')],
            toolChoice: ToolChoice::auto(),
        ));

        self::assertArrayNotHasKey('tool_choice', $this->transport->lastBody());
    }

    public function testDeveloperRoleQuirk(): void
    {
        $this->queueChat();
        $this->client(new Quirks(developerRole: true))->chat(Request::prompt('hi', 'Be brief.'));

        self::assertSame('developer', $this->transport->lastBody()['messages'][0]['role']);
    }

    public function testMultimodalPartsRequireQuirks(): void
    {
        $request = new Request([Message::user(new TextPart('look'), new ImagePart('bytes', 'image/png'))]);

        try {
            $this->client()->chat($request);
            self::fail('expected image input to be rejected');
        } catch (UnsupportedException $e) {
            self::assertStringContainsString('image input', $e->getMessage());
        }
        self::assertCount(0, $this->transport->requests());

        $this->queueChat();
        $this->client(new Quirks(images: true))->chat($request);

        self::assertSame([
            ['type' => 'text', 'text' => 'look'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('bytes')]],
        ], $this->transport->lastBody()['messages'][0]['content']);
    }

    public function testAudioAndFileParts(): void
    {
        $this->queueChat();
        $this->client(new Quirks(audio: true, files: true))->chat(new Request([Message::user(
            new AudioPart('sound', 'audio/mpeg'),
            new FilePart('%PDF', 'application/pdf', name: 'report.pdf'),
            FilePart::url('https://example.test/a.pdf', 'application/pdf'),
        )]));

        self::assertSame([
            ['type' => 'input_audio', 'input_audio' => ['data' => base64_encode('sound'), 'format' => 'mp3']],
            ['type' => 'file', 'file' => [
                'filename' => 'report.pdf',
                'file_data' => 'data:application/pdf;base64,' . base64_encode('%PDF'),
            ]],
            ['type' => 'file', 'file' => ['file_data' => 'https://example.test/a.pdf']],
        ], $this->transport->lastBody()['messages'][0]['content']);
    }

    public function testImageUrlAndDetailArePassedThrough(): void
    {
        $this->queueChat();
        $this->client(new Quirks(images: true))->chat(new Request([
            Message::user(new ImagePart(url: 'https://example.test/a.png', detail: 'high')),
        ]));

        self::assertSame([['type' => 'image_url', 'image_url' => [
            'url' => 'https://example.test/a.png',
            'detail' => 'high',
        ]]], $this->transport->lastBody()['messages'][0]['content']);
    }

    public function testAssistantToolCallsAndToolResultsRoundTrip(): void
    {
        $this->queueChat();
        $this->client()->chat(new Request([
            Message::userText('Weather?'),
            Message::assistant(
                new ReasoningPart('never sent back'),
                new ToolCall('call_1', 'weather', '{"city":"Cape Town"}'),
            ),
            Message::tool(ToolResult::text('call_1', 'weather', 'sunny')),
        ]));

        self::assertSame([
            ['role' => 'user', 'content' => 'Weather?'],
            ['role' => 'assistant', 'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'weather', 'arguments' => '{"city":"Cape Town"}'],
            ]]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'sunny'],
        ], $this->transport->lastBody()['messages']);
    }

    public function testAssistantTextOnlyKeepsContent(): void
    {
        $this->queueChat();
        $this->client()->chat(new Request([
            Message::userText('hi'),
            Message::assistantText('hello'),
            Message::userText('again'),
        ]));

        self::assertSame(['role' => 'assistant', 'content' => 'hello'], $this->transport->lastBody()['messages'][1]);
    }

    public function testNonTextToolResultsAreRejected(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->client()->chat(new Request([
            Message::tool(new ToolResult('call_1', 'x', [new ImagePart('bytes', 'image/png')])),
        ]));
    }

    public function testResponseWithToolCallsAndReasoning(): void
    {
        $this->queueChat([
            'role' => 'assistant',
            'content' => null,
            'reasoning_content' => 'thinking hard',
            'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'weather', 'arguments' => '{"city":"Cape Town"}'],
            ]],
        ], 'tool_calls');

        $response = $this->client(new Quirks(reasoningContentField: 'reasoning_content'))
            ->chat(Request::prompt('Weather?'));

        self::assertEquals([
            new ReasoningPart('thinking hard'),
            new ToolCall('call_1', 'weather', '{"city":"Cape Town"}'),
        ], $response->message->parts);
        self::assertSame(FinishReason::ToolCalls, $response->finishReason);
    }

    public function testFinishStopWithToolCallsBecomesToolCalls(): void
    {
        $this->queueChat([
            'role' => 'assistant',
            'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'x', 'arguments' => '']]],
        ], 'stop');

        $response = $this->client()->chat(Request::prompt('x'));

        self::assertSame(FinishReason::ToolCalls, $response->finishReason);
        self::assertEquals([new ToolCall('c1', 'x', '{}')], $response->message->parts);
    }

    public function testRefusalBecomesText(): void
    {
        $this->queueChat(['role' => 'assistant', 'content' => null, 'refusal' => 'I cannot help with that'], 'content_filter');

        $response = $this->client()->chat(Request::prompt('x'));

        self::assertSame('I cannot help with that', $response->text());
        self::assertSame(FinishReason::ContentFilter, $response->finishReason);
    }

    public function testEmptyChoicesAreTolerated(): void
    {
        $this->transport->pushJson(['id' => 'x', 'choices' => []]);

        $response = $this->client()->chat(Request::prompt('x'));

        self::assertSame(FinishReason::Other, $response->finishReason);
        self::assertSame('', $response->text());
    }

    public function testReasoningEffortAndProviderExtraAreMerged(): void
    {
        $this->queueChat();
        $this->client()->chat(new Request(
            messages: [Message::userText('x')],
            reasoning: new ReasoningConfig(effort: 'high'),
            extra: ['user' => 'u-1', 'temperature' => 0.1],
            providerOptions: ['compat' => ['temperature' => 0.9], 'other' => ['ignored' => true]],
        ));

        $body = $this->transport->lastBody();
        self::assertSame('high', $body['reasoning_effort']);
        self::assertSame('u-1', $body['user']);
        self::assertSame(0.9, $body['temperature']);
        self::assertArrayNotHasKey('ignored', $body);
    }

    public function testCustomReasoningRequestQuirk(): void
    {
        $this->queueChat();
        $quirks = new Quirks(reasoningRequest: static fn(ReasoningConfig $r): array => [
            'reasoning' => ['effort' => $r->effort, 'max_tokens' => $r->budgetTokens],
        ]);

        $this->client($quirks)->chat(new Request(
            messages: [Message::userText('x')],
            reasoning: new ReasoningConfig(effort: 'low', budgetTokens: 1024),
        ));

        self::assertSame(['effort' => 'low', 'max_tokens' => 1024], $this->transport->lastBody()['reasoning']);
    }

    public function testJsonSchemaFormatNeedsTheQuirk(): void
    {
        $format = ResponseFormat::jsonSchema('answer', ['type' => 'object'], true);

        try {
            $this->client()->chat(new Request([Message::userText('x')], format: $format));
            self::fail('expected json_schema to be rejected');
        } catch (UnsupportedException $e) {
            self::assertStringContainsString('json_schema', $e->getMessage());
        }

        $this->queueChat();
        $this->client(new Quirks(jsonSchema: true))->chat(new Request([Message::userText('x')], format: $format));

        self::assertSame([
            'type' => 'json_schema',
            'json_schema' => ['name' => 'answer', 'schema' => ['type' => 'object'], 'strict' => true],
        ], $this->transport->lastBody()['response_format']);
    }

    public function testPlainJsonFormat(): void
    {
        $this->queueChat();
        $this->client()->chat(new Request([Message::userText('x')], format: ResponseFormat::json()));

        self::assertSame(['type' => 'json_object'], $this->transport->lastBody()['response_format']);
    }

    public function testStreamParsesFramesAndUsage(): void
    {
        $this->transport->pushSse(implode('', [
            "data: {\"choices\":[{\"delta\":{\"reasoning_content\":\"think\"}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"content\":\"lo\"}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_1\","
                . "\"function\":{\"name\":\"weather\",\"arguments\":\"{\\\"city\\\":\"}}]}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,"
                . "\"function\":{\"arguments\":\"\\\"CPT\\\"}\"}}]},\"finish_reason\":\"tool_calls\"}]}\n\n",
            "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":7,\"completion_tokens\":2,\"total_tokens\":9}}\n\n",
            "data: [DONE]\n\n",
        ]));

        $client = $this->client(new Quirks(reasoningContentField: 'reasoning_content', streamUsage: true));
        $chunks = [];
        foreach ($client->stream(Request::prompt('Weather?')) as $chunk) {
            $chunks[] = $chunk->withoutRaw();
        }

        self::assertTrue($this->transport->lastBody()['stream']);
        self::assertSame(['include_usage' => true], $this->transport->lastBody()['stream_options']);

        self::assertSame(ChunkKind::Reasoning, $chunks[0]->kind);
        self::assertSame('think', $chunks[0]->text);
        self::assertSame(['Hel', 'lo'], [$chunks[1]->text, $chunks[2]->text]);
        self::assertSame(ChunkKind::Finish, end($chunks)->kind);
        self::assertSame(FinishReason::ToolCalls, end($chunks)->finishReason);
        self::assertSame(9, end($chunks)->usage?->totalTokens);

        $collected = Stream::collect($chunks);
        self::assertSame('Hello', $collected->text());
        self::assertEquals(
            new ToolCall('call_1', 'weather', '{"city":"CPT"}'),
            $collected->message->parts[2],
        );
    }

    public function testStreamRequestIsNotSentUntilIteration(): void
    {
        $this->transport->pushSse("data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\n");

        $stream = $this->client()->stream(Request::prompt('x'));
        self::assertCount(0, $this->transport->requests());

        $chunks = iterator_to_array($stream, false);
        self::assertCount(1, $this->transport->requests());
        self::assertSame([ChunkKind::Text, ChunkKind::Finish], array_map(
            static fn(Chunk $c): ChunkKind => $c->kind,
            $chunks,
        ));
    }

    public function testGroqStyleUsageEnvelope(): void
    {
        $this->transport->pushJson([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'hi'], 'finish_reason' => 'stop']],
            'x_groq' => ['usage' => ['prompt_tokens' => 4, 'completion_tokens' => 1]],
        ]);

        $usage = $this->client()->chat(Request::prompt('x'))->usage;

        self::assertSame(4, $usage->inputTokens);
        self::assertSame(5, $usage->totalTokens);
    }

    public function testUsageDetailsArePickedUp(): void
    {
        $this->transport->pushJson([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'hi'], 'finish_reason' => 'stop']],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
                'prompt_tokens_details' => ['cached_tokens' => 80],
                'completion_tokens_details' => ['reasoning_tokens' => 20],
            ],
        ]);

        $usage = $this->client()->chat(Request::prompt('x'))->usage;

        self::assertSame(80, $usage->cachedInputTokens);
        self::assertSame(20, $usage->reasoningTokens);
    }

    public function testEmbedRequiresThePathQuirk(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->client()->embed(new EmbedRequest(['a']));
    }

    public function testEmbedSortsVectorsByIndex(): void
    {
        $this->transport->pushJson([
            'model' => 'embed-1',
            'data' => [
                ['index' => 1, 'embedding' => [0.3, 0.4]],
                ['index' => 0, 'embedding' => [0.1, 0.2]],
            ],
            'usage' => ['prompt_tokens' => 6, 'total_tokens' => 6],
        ]);

        $response = $this->client(new Quirks(embedPath: '/embeddings'), 'embed-model')
            ->embed(new EmbedRequest(['first', 'second'], dimensions: 2));

        self::assertSame([[0.1, 0.2], [0.3, 0.4]], $response->embeddings);
        self::assertSame('embed-1', $response->model);
        self::assertSame(6, $response->usage->inputTokens);
        self::assertSame([
            'model' => 'embed-model',
            'input' => ['first', 'second'],
            'encoding_format' => 'float',
            'dimensions' => 2,
        ], $this->transport->lastBody());
        self::assertSame('https://api.example.test/v1/embeddings', $this->transport->lastRequest()->url);
    }
}
