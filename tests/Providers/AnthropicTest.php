<?php

declare(strict_types=1);

namespace LlmKit\Tests\Providers;

use LlmKit\AudioPart;
use LlmKit\CacheConfig;
use LlmKit\ChunkKind;
use LlmKit\Config;
use LlmKit\Exception\ApiException;
use LlmKit\Exception\InvalidRequestException;
use LlmKit\Exception\MissingApiKeyException;
use LlmKit\Exception\UnsupportedException;
use LlmKit\FilePart;
use LlmKit\FinishReason;
use LlmKit\Http\MockTransport;
use LlmKit\ImagePart;
use LlmKit\Message;
use LlmKit\Providers\Anthropic\AnthropicClient;
use LlmKit\Providers\Anthropic\AnthropicProvider;
use LlmKit\ReasoningConfig;
use LlmKit\ReasoningPart;
use LlmKit\Request;
use LlmKit\ResponseFormat;
use LlmKit\Role;
use LlmKit\Stream;
use LlmKit\TextPart;
use LlmKit\Tool;
use LlmKit\ToolCall;
use LlmKit\ToolChoice;
use LlmKit\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnthropicClient::class)]
#[CoversClass(AnthropicProvider::class)]
final class AnthropicTest extends TestCase
{
    private MockTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
    }

    private function client(string $model = 'claude-sonnet-4-5', ?Config $config = null): AnthropicClient
    {
        $config ??= new Config();

        return AnthropicClient::create(
            $model,
            $config->withTransport($this->transport)->withApiKey('sk-ant'),
        );
    }

    private function queueText(string $text = 'Hello'): void
    {
        $this->transport->pushJson([
            'id' => 'msg_1',
            'model' => 'claude-sonnet-4-5-20250929',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
        ]);
    }

    public function testMatchesClaudeNames(): void
    {
        $provider = new AnthropicProvider();

        self::assertTrue($provider->matches('claude-opus-5'));
        self::assertTrue($provider->matches('CLAUDE-3-5-haiku'));
        self::assertFalse($provider->matches('gpt-5'));
    }

    public function testMissingKeyFailsBeforeAnyCall(): void
    {
        $this->expectException(MissingApiKeyException::class);
        AnthropicClient::create('claude-opus-5', (new Config())->withTransport($this->transport));
    }

    public function testChatBodyHeadersAndResponse(): void
    {
        $this->queueText();
        $response = $this->client()->chat(new Request(
            messages: [Message::system('Be brief.'), Message::userText('Hi')],
            temperature: 0.4,
            stop: ['END'],
        ));

        self::assertSame([
            'model' => 'claude-sonnet-4-5',
            'max_tokens' => AnthropicClient::DEFAULT_MAX_TOKENS,
            'system' => 'Be brief.',
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]],
            'temperature' => 0.4,
            'stop_sequences' => ['END'],
        ], $this->transport->lastBody());

        $request = $this->transport->lastRequest();
        self::assertSame('https://api.anthropic.com/v1/messages', $request->url);
        self::assertSame(['sk-ant'], $request->headers['x-api-key']);
        self::assertSame([AnthropicClient::DEFAULT_VERSION], $request->headers['anthropic-version']);

        self::assertSame('Hello', $response->text());
        self::assertSame('msg_1', $response->id);
        self::assertSame(FinishReason::Stop, $response->finishReason);
        self::assertSame(14, $response->usage->totalTokens);
    }

    public function testVersionAndBetaHeadersCanBeOverridden(): void
    {
        $this->queueText();
        $config = (new Config())
            ->withHeader(AnthropicClient::HEADER_VERSION, '2024-10-22')
            ->withHeader(AnthropicClient::HEADER_BETA, 'context-1m-2025-08-07');

        $this->client('claude-opus-5', $config)->chat(Request::prompt('x'));

        $headers = $this->transport->lastRequest()->headers;
        self::assertSame(['2024-10-22'], $headers['anthropic-version']);
        self::assertSame(['context-1m-2025-08-07'], $headers['anthropic-beta']);
    }

    public function testConsecutiveSameRoleTurnsAreMerged(): void
    {
        $this->queueText();
        $this->client()->chat(new Request([
            Message::userText('first'),
            Message::userText('second'),
            Message::assistantText('reply'),
            Message::assistant(new ToolCall('call_1', 'weather', '{"city":"CPT"}')),
            Message::tool(ToolResult::text('call_1', 'weather', 'sunny')),
        ]));

        self::assertSame([
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'first'],
                ['type' => 'text', 'text' => 'second'],
            ]],
            ['role' => 'assistant', 'content' => [
                ['type' => 'text', 'text' => 'reply'],
                ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'weather', 'input' => ['city' => 'CPT']],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => 'sunny'],
            ]],
        ], $this->transport->lastBody()['messages']);
    }

    public function testEmptyMessagesAreDropped(): void
    {
        $this->queueText();
        $this->client()->chat(new Request([
            Message::user(),
            Message::userText('only this'),
        ]));

        self::assertCount(1, $this->transport->lastBody()['messages']);
    }

    public function testThinkingAndRedactedBlocksAreReplayed(): void
    {
        $this->queueText();
        $this->client()->chat(new Request([
            Message::userText('think'),
            Message::assistant(
                new ReasoningPart('step one', 'sig-abc'),
                new ReasoningPart(encrypted: 'redacted-blob'),
                new ReasoningPart('unsigned but kept'),
                new TextPart('answer'),
            ),
        ]));

        self::assertSame([
            ['type' => 'thinking', 'thinking' => 'step one', 'signature' => 'sig-abc'],
            ['type' => 'redacted_thinking', 'data' => 'redacted-blob'],
            ['type' => 'thinking', 'thinking' => 'unsigned but kept'],
            ['type' => 'text', 'text' => 'answer'],
        ], $this->transport->lastBody()['messages'][1]['content']);
    }

    public function testImagesDocumentsAndUrls(): void
    {
        $this->queueText();
        $this->client()->chat(new Request([Message::user(
            new ImagePart('bytes', 'image/png'),
            ImagePart::url('https://example.test/a.png'),
            new FilePart('%PDF', 'application/pdf', name: 'report.pdf'),
            new FilePart('plain words', 'text/plain', name: 'notes.txt'),
            FilePart::url('https://example.test/a.pdf', 'application/pdf'),
        )]));

        self::assertSame([
            ['type' => 'image', 'source' => [
                'type' => 'base64',
                'media_type' => 'image/png',
                'data' => base64_encode('bytes'),
            ]],
            ['type' => 'image', 'source' => ['type' => 'url', 'url' => 'https://example.test/a.png']],
            ['type' => 'document', 'title' => 'report.pdf', 'source' => [
                'type' => 'base64',
                'media_type' => 'application/pdf',
                'data' => base64_encode('%PDF'),
            ]],
            ['type' => 'document', 'title' => 'notes.txt', 'source' => [
                'type' => 'text',
                'media_type' => 'text/plain',
                'data' => 'plain words',
            ]],
            ['type' => 'document', 'source' => ['type' => 'url', 'url' => 'https://example.test/a.pdf']],
        ], $this->transport->lastBody()['messages'][0]['content']);
    }

    public function testAudioIsRejectedBeforeSending(): void
    {
        try {
            $this->client()->chat(new Request([Message::user(new AudioPart('sound', 'audio/mpeg'))]));
            self::fail('expected audio to be rejected');
        } catch (UnsupportedException $e) {
            self::assertStringContainsString('AudioPart', $e->getMessage());
        }
        self::assertCount(0, $this->transport->requests());
    }

    public function testToolResultWithImagesBecomesBlocks(): void
    {
        $this->queueText();
        $this->client()->chat(new Request([
            Message::tool(new ToolResult('call_1', 'chart', [
                new TextPart('see this'),
                new ImagePart('png', 'image/png'),
            ], true)),
        ]));

        self::assertSame([[
            'type' => 'tool_result',
            'tool_use_id' => 'call_1',
            'is_error' => true,
            'content' => [
                ['type' => 'text', 'text' => 'see this'],
                ['type' => 'image', 'source' => [
                    'type' => 'base64',
                    'media_type' => 'image/png',
                    'data' => base64_encode('png'),
                ]],
            ],
        ]], $this->transport->lastBody()['messages'][0]['content']);
    }

    public function testToolsAndToolChoice(): void
    {
        $this->queueText();
        $this->client()->chat(new Request(
            messages: [Message::userText('x')],
            tools: [new Tool('weather', 'Current weather'), new Tool('clock')],
            toolChoice: ToolChoice::required(),
        ));

        $body = $this->transport->lastBody();
        self::assertSame([
            ['name' => 'weather', 'description' => 'Current weather', 'input_schema' => ['type' => 'object']],
            ['name' => 'clock', 'input_schema' => ['type' => 'object']],
        ], $body['tools']);
        self::assertSame(['type' => 'any'], $body['tool_choice']);
    }

    public function testReasoningModesAndDisplay(): void
    {
        $this->queueText();
        $this->client()->chat(new Request(
            messages: [Message::userText('x')],
            reasoning: new ReasoningConfig(effort: 'high', summary: 'auto'),
        ));

        $body = $this->transport->lastBody();
        self::assertSame(['type' => 'adaptive', 'display' => 'summarized'], $body['thinking']);
        self::assertSame(['effort' => 'high'], $body['output_config']);

        $this->queueText();
        $this->client()->chat(new Request(
            messages: [Message::userText('x')],
            reasoning: new ReasoningConfig(budgetTokens: 4096),
        ));

        self::assertSame(
            ['type' => 'enabled', 'budget_tokens' => 4096],
            $this->transport->lastBody()['thinking'],
        );
    }

    public function testJsonSchemaFormatAndItsLimits(): void
    {
        $this->queueText();
        $this->client()->chat(new Request(
            messages: [Message::userText('x')],
            format: ResponseFormat::jsonSchema('answer', ['type' => 'object']),
        ));

        self::assertSame(
            ['format' => ['type' => 'json_schema', 'schema' => ['type' => 'object']]],
            $this->transport->lastBody()['output_config'],
        );

        $this->expectException(UnsupportedException::class);
        $this->client()->chat(new Request([Message::userText('x')], format: ResponseFormat::json()));
    }

    public function testCacheBreakpointsAreStableAndCapped(): void
    {
        $this->queueText();
        $this->client()->chat(new Request(
            messages: [
                Message::system('Long prompt'),
                Message::userText('one'),
                Message::assistantText('a'),
                Message::userText('two'),
                Message::assistantText('b'),
                Message::userText('three'),
            ],
            tools: [new Tool('a'), new Tool('b')],
            cache: new CacheConfig(system: true, tools: true, turns: 4, ttl: CacheConfig::TTL_1H),
        ));

        $body = $this->transport->lastBody();
        $control = ['type' => 'ephemeral', 'ttl' => '1h'];

        self::assertArrayNotHasKey('cache_control', $body['tools'][0]);
        self::assertSame($control, $body['tools'][1]['cache_control']);
        self::assertSame([['type' => 'text', 'text' => 'Long prompt', 'cache_control' => $control]], $body['system']);

        // Four slots total: tools, system, then only the last two user turns.
        $userTurns = array_values(array_filter(
            $body['messages'],
            static fn(array $m): bool => $m['role'] === 'user',
        ));
        self::assertCount(3, $userTurns);
        self::assertArrayNotHasKey('cache_control', $userTurns[0]['content'][0]);
        self::assertSame($control, $userTurns[1]['content'][0]['cache_control']);
        self::assertSame($control, $userTurns[2]['content'][0]['cache_control']);
    }

    public function testSystemBecomesBlocksWheneverCacheIsSet(): void
    {
        $this->queueText();
        $this->client()->chat(new Request(
            messages: [Message::system('prefix'), Message::userText('x')],
            cache: new CacheConfig(turns: 1),
        ));

        $body = $this->transport->lastBody();
        self::assertSame([['type' => 'text', 'text' => 'prefix']], $body['system']);
        self::assertSame(
            ['type' => 'ephemeral'],
            $body['messages'][0]['content'][0]['cache_control'],
        );
    }

    public function testUnsupportedCacheTtlFailsBeforeSending(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->client()->chat(new Request(
            messages: [Message::userText('x')],
            cache: new CacheConfig(system: true, ttl: '1d'),
        ));
    }

    public function testResponseBlocksBecomeParts(): void
    {
        $this->transport->pushJson([
            'id' => 'msg_2',
            'model' => 'claude-opus-5',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'hmm', 'signature' => 'sig'],
                ['type' => 'redacted_thinking', 'data' => 'blob'],
                ['type' => 'text', 'text' => 'the answer'],
                ['type' => 'tool_use', 'id' => 'call_9', 'name' => 'weather', 'input' => ['city' => 'CPT']],
            ],
            'stop_reason' => 'tool_use',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'cache_read_input_tokens' => 100,
                'cache_creation_input_tokens' => 20,
            ],
        ]);

        $response = $this->client()->chat(Request::prompt('x'));

        self::assertEquals([
            new ReasoningPart('hmm', 'sig'),
            new ReasoningPart(encrypted: 'blob'),
            new TextPart('the answer'),
            new ToolCall('call_9', 'weather', '{"city":"CPT"}'),
        ], $response->message->parts);
        self::assertSame(FinishReason::ToolCalls, $response->finishReason);

        // input_tokens is the whole prompt: uncached + cache read + cache write.
        self::assertSame(130, $response->usage->inputTokens);
        self::assertSame(100, $response->usage->cachedInputTokens);
        self::assertSame(20, $response->usage->cacheWriteTokens);
        self::assertSame(135, $response->usage->totalTokens);
    }

    /** @return iterable<string,array{string,FinishReason}> */
    public static function stopReasons(): iterable
    {
        yield 'end turn' => ['end_turn', FinishReason::Stop];
        yield 'stop sequence' => ['stop_sequence', FinishReason::Stop];
        yield 'max tokens' => ['max_tokens', FinishReason::Length];
        yield 'tool use' => ['tool_use', FinishReason::ToolCalls];
        yield 'refusal' => ['refusal', FinishReason::ContentFilter];
        yield 'unknown' => ['pause_turn', FinishReason::Other];
    }

    #[DataProvider('stopReasons')]
    public function testStopReasons(string $reason, FinishReason $expected): void
    {
        $this->transport->pushJson(['content' => [], 'stop_reason' => $reason, 'usage' => []]);

        self::assertSame($expected, $this->client()->chat(Request::prompt('x'))->finishReason);
    }

    public function testStreamWithThinkingToolsAndSignatures(): void
    {
        $this->transport->pushSse(implode('', [
            "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"usage\":"
                . "{\"input_tokens\":10,\"cache_read_input_tokens\":90}}}\n\n",
            "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,"
                . "\"content_block\":{\"type\":\"thinking\"}}\n\n",
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,"
                . "\"delta\":{\"type\":\"thinking_delta\",\"thinking\":\"step \"}}\n\n",
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,"
                . "\"delta\":{\"type\":\"thinking_delta\",\"thinking\":\"by step\"}}\n\n",
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,"
                . "\"delta\":{\"type\":\"signature_delta\",\"signature\":\"sig-1\"}}\n\n",
            "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":1,"
                . "\"content_block\":{\"type\":\"redacted_thinking\",\"data\":\"blob\"}}\n\n",
            "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":2,"
                . "\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n",
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":2,"
                . "\"delta\":{\"type\":\"text_delta\",\"text\":\"Hi\"}}\n\n",
            "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":3,"
                . "\"content_block\":{\"type\":\"tool_use\",\"id\":\"call_1\",\"name\":\"weather\"}}\n\n",
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":3,"
                . "\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"{\\\"city\\\":\\\"CPT\\\"}\"}}\n\n",
            "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"tool_use\"},"
                . "\"usage\":{\"output_tokens\":42}}\n\n",
            "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n",
        ]));

        $chunks = [];
        foreach ($this->client()->stream(Request::prompt('Weather?')) as $chunk) {
            $chunks[] = $chunk->withoutRaw();
        }

        self::assertTrue($this->transport->lastBody()['stream']);
        self::assertSame(ChunkKind::Finish, end($chunks)->kind);

        $collected = Stream::collect($chunks);
        self::assertEquals([
            new ReasoningPart('step by step', 'sig-1'),
            new ReasoningPart(encrypted: 'blob'),
            new TextPart('Hi'),
            new ToolCall('call_1', 'weather', '{"city":"CPT"}'),
        ], $collected->message->parts);
        self::assertSame(FinishReason::ToolCalls, $collected->finishReason);
        self::assertSame(Role::Assistant, $collected->message->role);

        // message_delta usage repeats only what changed.
        self::assertSame(100, $collected->usage->inputTokens);
        self::assertSame(90, $collected->usage->cachedInputTokens);
        self::assertSame(42, $collected->usage->outputTokens);
    }

    public function testStreamStopsAtMessageStop(): void
    {
        $this->transport->pushSse(implode('', [
            "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n",
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,"
                . "\"delta\":{\"type\":\"text_delta\",\"text\":\"never\"}}\n\n",
        ]));

        $chunks = iterator_to_array($this->client()->stream(Request::prompt('x')), false);

        self::assertCount(1, $chunks);
        self::assertSame(ChunkKind::Finish, $chunks[0]->kind);
    }

    public function testStreamErrorEventAfterOkStatus(): void
    {
        $this->transport->pushSse(
            "event: error\ndata: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\","
            . "\"message\":\"Overloaded\"}}\n\n",
        );

        try {
            iterator_to_array($this->client()->stream(Request::prompt('x')), false);
            self::fail('expected the error event to throw');
        } catch (ApiException $e) {
            self::assertSame('overloaded_error', $e->errorCode);
            self::assertSame('Overloaded', $e->detail);
            self::assertSame(0, $e->status);
        }
    }

    public function testCountTokensStripsGenerationParameters(): void
    {
        $this->transport->pushJson(['input_tokens' => 2095]);

        $count = $this->client()->countTokens(new Request(
            messages: [Message::system('Be brief.'), Message::userText('Hi')],
            tools: [new Tool('weather')],
            maxTokens: 500,
            temperature: 0.5,
            reasoning: new ReasoningConfig(budgetTokens: 1024),
        ));

        self::assertSame(2095, $count);
        self::assertSame(
            'https://api.anthropic.com/v1/messages/count_tokens',
            $this->transport->lastRequest()->url,
        );
        self::assertSame([
            'model' => 'claude-sonnet-4-5',
            'system' => 'Be brief.',
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]],
            'tools' => [['name' => 'weather', 'input_schema' => ['type' => 'object']]],
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 1024],
        ], $this->transport->lastBody());
    }

    public function testProviderExtraIsMergedIntoTheBody(): void
    {
        $this->queueText();
        $this->client()->chat(new Request(
            messages: [Message::userText('x')],
            extra: ['metadata' => ['user_id' => 'u1']],
            providerOptions: ['anthropic' => ['top_k' => 5]],
        ));

        $body = $this->transport->lastBody();
        self::assertSame(['user_id' => 'u1'], $body['metadata']);
        self::assertSame(5, $body['top_k']);
    }
}
