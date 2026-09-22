<?php

declare(strict_types=1);

namespace LlmKit\Tests\Providers;

use LlmKit\AudioPart;
use LlmKit\ChunkKind;
use LlmKit\Config;
use LlmKit\EmbedInputType;
use LlmKit\EmbedRequest;
use LlmKit\Exception\ApiException;
use LlmKit\Exception\InvalidRequestException;
use LlmKit\Exception\MissingApiKeyException;
use LlmKit\Exception\RateLimitedException;
use LlmKit\FilePart;
use LlmKit\FinishReason;
use LlmKit\Http\MockTransport;
use LlmKit\ImagePart;
use LlmKit\Message;
use LlmKit\Providers\Vertex\VertexClient;
use LlmKit\Providers\Vertex\VertexProvider;
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

#[CoversClass(VertexClient::class)]
#[CoversClass(VertexProvider::class)]
final class VertexTest extends TestCase
{
    private MockTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
    }

    private function config(): Config
    {
        return (new Config())
            ->withTransport($this->transport)
            ->withValue(VertexClient::OPTION_PROJECT, 'my-project')
            ->withValue(VertexClient::OPTION_ACCESS_TOKEN, 'ya29.token');
    }

    private function client(string $model = 'gemini-2.5-pro', ?Config $config = null): VertexClient
    {
        return VertexClient::create($model, $config ?? $this->config());
    }

    private function queueText(string $text = 'Hello'): void
    {
        $this->transport->pushJson([
            'responseId' => 'resp-1',
            'modelVersion' => 'gemini-2.5-pro-001',
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => $text]]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 4,
                'totalTokenCount' => 14,
            ],
        ]);
    }

    /** @return iterable<string,array{string,bool}> */
    public static function modelNames(): iterable
    {
        yield 'gemini' => ['gemini-2.5-flash', true];
        yield 'imagen' => ['imagen-4.0', true];
        yield 'embedding' => ['text-embedding-005', true];
        yield 'openai' => ['gpt-5', false];
    }

    #[DataProvider('modelNames')]
    public function testMatches(string $model, bool $expected): void
    {
        self::assertSame($expected, (new VertexProvider())->matches($model));
    }

    public function testMissingProjectIsReported(): void
    {
        $this->expectException(MissingApiKeyException::class);
        VertexClient::create('gemini-2.5-pro', (new Config())->withTransport($this->transport));
    }

    public function testRegionalHostAndModelPath(): void
    {
        $this->queueText();
        $this->client()->chat(Request::prompt('Hi'));

        self::assertSame(
            'https://us-central1-aiplatform.googleapis.com/v1/projects/my-project/locations/'
            . 'us-central1/publishers/google/models/gemini-2.5-pro:generateContent',
            $this->transport->lastRequest()->url,
        );
        self::assertSame(['Bearer ya29.token'], $this->transport->lastRequest()->headers['Authorization']);
    }

    public function testGlobalLocationUsesTheGlobalHost(): void
    {
        $this->queueText();
        $this->client('gemini-2.5-pro', $this->config()->withValue(VertexClient::OPTION_LOCATION, 'global'))
            ->chat(Request::prompt('Hi'));

        self::assertStringStartsWith(
            'https://aiplatform.googleapis.com/v1/projects/my-project/locations/global/',
            $this->transport->lastRequest()->url,
        );
    }

    public function testTokenProviderIsCalledPerRequest(): void
    {
        $this->queueText();
        $this->queueText();
        $calls = 0;
        $config = (new Config())
            ->withTransport($this->transport)
            ->withValue(VertexClient::OPTION_PROJECT, 'my-project')
            ->withValue(VertexClient::OPTION_TOKEN_PROVIDER, function () use (&$calls): string {
                ++$calls;

                return 'token-' . $calls;
            });

        $client = $this->client('gemini-2.5-pro', $config);
        $client->chat(Request::prompt('one'));
        $client->chat(Request::prompt('two'));

        self::assertSame(2, $calls);
        self::assertSame(['Bearer token-2'], $this->transport->lastRequest()->headers['Authorization']);
    }

    public function testSystemInstructionAndGenerationConfig(): void
    {
        $this->queueText();
        $this->client()->chat(new Request(
            messages: [Message::system('Be brief.'), Message::system('Be kind.'), Message::userText('Hi')],
            maxTokens: 200,
            temperature: 0.3,
            stop: ['END'],
            seed: 11,
            format: ResponseFormat::jsonSchema('answer', ['type' => 'object']),
            reasoning: new ReasoningConfig(),
        ));

        $body = $this->transport->lastBody();
        self::assertSame(['parts' => [['text' => "Be brief.\nBe kind."]]], $body['systemInstruction']);
        self::assertSame([['role' => 'user', 'parts' => [['text' => 'Hi']]]], $body['contents']);
        self::assertSame([
            'temperature' => 0.3,
            'maxOutputTokens' => 200,
            'stopSequences' => ['END'],
            'seed' => 11,
            'responseMimeType' => 'application/json',
            'responseJsonSchema' => ['type' => 'object'],
            'thinkingConfig' => ['includeThoughts' => true, 'thinkingBudget' => -1],
        ], $body['generationConfig']);
    }

    public function testMediaPartsAndConsecutiveTurnMerging(): void
    {
        $this->queueText();
        $this->client()->chat(new Request([
            Message::user(new ImagePart('png', 'image/png'), new AudioPart('wav', 'audio/wav')),
            Message::user(FilePart::url('gs://bucket/report.pdf')),
        ]));

        self::assertSame([[
            'role' => 'user',
            'parts' => [
                ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('png')]],
                ['inlineData' => ['mimeType' => 'audio/wav', 'data' => base64_encode('wav')]],
                ['fileData' => ['mimeType' => 'application/pdf', 'fileUri' => 'gs://bucket/report.pdf']],
            ],
        ]], $this->transport->lastBody()['contents']);
    }

    public function testToolsToolConfigAndFunctionResponses(): void
    {
        $this->queueText();
        $this->client()->chat(new Request(
            messages: [
                Message::userText('Weather?'),
                Message::assistant(
                    new ReasoningPart(signature: 'sig-1'),
                    new ToolCall('call_1', 'weather', '{"city":"CPT"}'),
                ),
                Message::tool(ToolResult::text('call_1', 'weather', '{"temp":24}')),
            ],
            tools: [new Tool('weather', 'Current weather', ['type' => 'object'])],
            toolChoice: ToolChoice::named('weather'),
        ));

        $body = $this->transport->lastBody();
        self::assertSame([['functionDeclarations' => [[
            'name' => 'weather',
            'description' => 'Current weather',
            'parameters' => ['type' => 'object'],
        ]]]], $body['tools']);
        self::assertSame(
            ['functionCallingConfig' => ['mode' => 'ANY', 'allowedFunctionNames' => ['weather']]],
            $body['toolConfig'],
        );
        // A text-less reasoning part rides along as the next part's signature.
        self::assertSame([[
            'functionCall' => ['name' => 'weather', 'args' => ['city' => 'CPT']],
            'thoughtSignature' => 'sig-1',
        ]], $body['contents'][1]['parts']);
        self::assertSame([[
            'functionResponse' => ['name' => 'weather', 'response' => ['result' => ['temp' => 24]]],
        ]], $body['contents'][2]['parts']);
    }

    public function testToolResultsNeedANameAndErrorsUseTheErrorKey(): void
    {
        $this->queueText();
        $this->client()->chat(new Request([
            Message::tool(ToolResult::error('call_1', 'weather', 'boom')),
        ]));

        self::assertSame(
            ['functionResponse' => ['name' => 'weather', 'response' => ['error' => 'boom']]],
            $this->transport->lastBody()['contents'][0]['parts'][0],
        );

        $this->expectException(InvalidRequestException::class);
        $this->client()->chat(new Request([Message::tool(ToolResult::text('call_1', '', 'x'))]));
    }

    public function testResponsePartsIncludingThoughtSignatures(): void
    {
        $this->transport->pushJson([
            'responseId' => 'resp-2',
            'candidates' => [[
                'content' => ['parts' => [
                    ['text' => 'thinking out loud', 'thought' => true, 'thoughtSignature' => 'sig-a'],
                    ['text' => 'the answer', 'thoughtSignature' => 'sig-b'],
                    ['functionCall' => ['name' => 'weather', 'args' => ['city' => 'CPT']]],
                ]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 20,
                'candidatesTokenCount' => 5,
                'thoughtsTokenCount' => 12,
                'cachedContentTokenCount' => 8,
            ],
        ]);

        $response = $this->client()->chat(Request::prompt('x'));

        self::assertEquals([
            new ReasoningPart('thinking out loud', 'sig-a'),
            new ReasoningPart(signature: 'sig-b'),
            new TextPart('the answer'),
            new ToolCall('call_1', 'weather', '{"city":"CPT"}'),
        ], $response->message->parts);
        self::assertSame(FinishReason::ToolCalls, $response->finishReason);
        self::assertSame(17, $response->usage->outputTokens);
        self::assertSame(12, $response->usage->reasoningTokens);
        self::assertSame(8, $response->usage->cachedInputTokens);
        self::assertSame(37, $response->usage->totalTokens);
    }

    public function testBlockedPromptsBecomeContentFilter(): void
    {
        $this->transport->pushJson([
            'promptFeedback' => ['blockReason' => 'SAFETY'],
            'candidates' => [],
        ]);

        self::assertSame(
            FinishReason::ContentFilter,
            $this->client()->chat(Request::prompt('x'))->finishReason,
        );
    }

    public function testGoogleErrorStatusReplacesTheNumericCode(): void
    {
        $this->transport->push(json_encode([
            'error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED'],
        ]) ?: '', 429);

        try {
            $this->client()->chat(Request::prompt('x'));
            self::fail('expected an API exception');
        } catch (ApiException $e) {
            self::assertInstanceOf(RateLimitedException::class, $e);
            self::assertSame('RESOURCE_EXHAUSTED', $e->errorCode);
            self::assertSame('Quota exceeded', $e->detail);
        }
    }

    public function testStreamUsesAltSseAndSplitsSignatures(): void
    {
        $this->transport->pushSse(implode('', [
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"think\",\"thought\":true}]}}]}\n\n",
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"ing\",\"thought\":true,"
                . "\"thoughtSignature\":\"sig-a\"}]}}]}\n\n",
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Hello\"}]}}]}\n\n",
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"functionCall\":{\"name\":\"weather\","
                . "\"args\":{\"city\":\"CPT\"}}}]},\"finishReason\":\"STOP\"}],"
                . "\"usageMetadata\":{\"promptTokenCount\":3,\"candidatesTokenCount\":2,\"totalTokenCount\":5}}\n\n",
        ]));

        $chunks = [];
        foreach ($this->client()->stream(Request::prompt('x')) as $chunk) {
            $chunks[] = $chunk->withoutRaw();
        }

        self::assertStringEndsWith(':streamGenerateContent?alt=sse', $this->transport->lastRequest()->url);
        self::assertSame(ChunkKind::Finish, end($chunks)->kind);

        $collected = Stream::collect($chunks);
        self::assertEquals([
            new ReasoningPart('thinking', 'sig-a'),
            new TextPart('Hello'),
            new ToolCall('call_1', 'weather', '{"city":"CPT"}'),
        ], $collected->message->parts);
        self::assertSame(FinishReason::ToolCalls, $collected->finishReason);
        self::assertSame(5, $collected->usage->totalTokens);
    }

    public function testStreamErrorFrameAfterOkStatus(): void
    {
        $this->transport->pushSse(
            "data: {\"error\":{\"code\":503,\"message\":\"overloaded\",\"status\":\"UNAVAILABLE\"}}\n\n",
        );

        try {
            iterator_to_array($this->client()->stream(Request::prompt('x')), false);
            self::fail('expected an API exception');
        } catch (ApiException $e) {
            self::assertSame('UNAVAILABLE', $e->errorCode);
            self::assertSame(503, $e->status);
        }
    }

    public function testEmbedUsesPredict(): void
    {
        $this->transport->pushJson([
            'predictions' => [
                ['embeddings' => ['values' => [0.1, 0.2], 'statistics' => ['token_count' => 3]]],
                ['embeddings' => ['values' => [0.3, 0.4], 'statistics' => ['token_count' => 4]]],
            ],
        ]);

        $response = $this->client('text-embedding-005')->embed(new EmbedRequest(
            ['a', 'b'],
            dimensions: 256,
            inputType: EmbedInputType::Query,
        ));

        self::assertSame([[0.1, 0.2], [0.3, 0.4]], $response->embeddings);
        self::assertSame(7, $response->usage->totalTokens);
        self::assertSame([
            'instances' => [
                ['content' => 'a', 'task_type' => 'RETRIEVAL_QUERY'],
                ['content' => 'b', 'task_type' => 'RETRIEVAL_QUERY'],
            ],
            'parameters' => ['outputDimensionality' => 256, 'autoTruncate' => true],
        ], $this->transport->lastBody());
        self::assertStringEndsWith('text-embedding-005:predict', $this->transport->lastRequest()->url);
    }
}
