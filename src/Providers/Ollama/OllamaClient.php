<?php

declare(strict_types=1);

namespace LlmKit\Providers\Ollama;

use Generator;
use LlmKit\Chatter;
use LlmKit\Chunk;
use LlmKit\ChunkKind;
use LlmKit\Config;
use LlmKit\Embedder;
use LlmKit\EmbedRequest;
use LlmKit\EmbedResponse;
use LlmKit\Exception\InvalidRequestException;
use LlmKit\Http\Endpoint;
use LlmKit\Internal\Arr;
use LlmKit\Internal\Json;
use LlmKit\Request;
use LlmKit\Response;
use LlmKit\Streamer;
use LlmKit\ToolCallDelta;
use LlmKit\Usage;

/**
 * OllamaClient talks to a local or remote Ollama daemon over its native
 * /api/chat and /api/embed endpoints. Tool calls have no IDs on the wire, so
 * they are numbered call_1, call_2, … in order.
 */
final class OllamaClient implements Chatter, Embedder, Streamer
{
    /** ID is the provider identifier used in "ollama/<model>" names. */
    public const string ID = 'ollama';

    /** DEFAULT_HOST is used when OLLAMA_HOST is unset. */
    public const string DEFAULT_HOST = 'http://localhost:11434';

    public const string HOST_ENV = 'OLLAMA_HOST';

    /**
     * OPTION_KEEP_ALIVE controls how long the model stays loaded after a
     * request (Ollama duration syntax such as "5m", or "-1" for forever).
     */
    public const string OPTION_KEEP_ALIVE = 'ollama.keep_alive';

    private const string PATH_CHAT = '/api/chat';
    private const string PATH_EMBED = '/api/embed';

    private readonly OllamaMapper $mapper;

    private function __construct(
        private readonly string $modelName,
        private readonly Endpoint $endpoint,
        private readonly string $keepAlive,
    ) {
        $this->mapper = new OllamaMapper($modelName, $keepAlive);
    }

    /** create builds a client. The host comes from OLLAMA_HOST by default. */
    public static function create(string $model, ?Config $config = null): self
    {
        $config ??= new Config();
        $host = $config->baseUrl;
        if ($host === null || $host === '') {
            $env = getenv(self::HOST_ENV);
            $host = is_string($env) ? $env : '';
        }
        $config = $config->withBaseUrl(self::normaliseHost($host));
        $endpoint = Endpoint::create(self::ID, $config, self::DEFAULT_HOST);
        if ($config->apiKey !== null && $config->apiKey !== '') {
            $endpoint = $endpoint->withBearerAuth($config->apiKey);
        }

        return new self($model, $endpoint, $config->stringValue(self::OPTION_KEEP_ALIVE));
    }

    /** matchesModel claims names with an Ollama tag ("llama3.2:3b") or an hf.co path. */
    public static function matchesModel(string $model): bool
    {
        return str_contains($model, ':') || str_starts_with($model, 'hf.co/');
    }

    public function provider(): string
    {
        return self::ID;
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function chat(Request $request): Response
    {
        $raw = $this->endpoint->postJson(self::PATH_CHAT, $this->mapper->body($request, false));

        return $this->mapper->toResponse(Json::decodeObject($raw, 'decode response'))->withRaw($raw);
    }

    public function stream(Request $request): Generator
    {
        $body = $this->mapper->body($request, true);
        $calls = 0;
        $final = [];
        foreach ($this->endpoint->postNdjson(self::PATH_CHAT, $body) as $line) {
            $frame = Json::decodeObject($line, 'decode stream chunk');
            if (($frame['done'] ?? false) === true) {
                $final = $frame;
            }
            $message = Arr::obj($frame, 'message');
            if (Arr::str($message, 'thinking') !== '') {
                yield new Chunk(ChunkKind::Reasoning, text: Arr::str($message, 'thinking'), raw: $line);
            }
            if (Arr::str($message, 'content') !== '') {
                yield Chunk::text(Arr::str($message, 'content'), $line);
            }
            foreach (Arr::objects($message, 'tool_calls') as $call) {
                $toolCall = OllamaMapper::toolCall($call, $calls);
                yield Chunk::toolCall(new ToolCallDelta(
                    index: $calls,
                    id: $toolCall->id,
                    name: $toolCall->name,
                    arguments: $toolCall->arguments,
                ), $line);
                ++$calls;
            }
        }

        yield Chunk::finish(
            OllamaMapper::finishReason(Arr::str($final, 'done_reason'), $calls > 0),
            OllamaMapper::usage($final),
        );
    }

    public function embed(EmbedRequest $request): EmbedResponse
    {
        if ($request->inputs === []) {
            throw new InvalidRequestException(self::ID . ': no inputs');
        }
        $body = $this->mapper->embedBody(
            $request->dimensions,
            $request->inputs,
            $request->providerExtra(self::ID),
        );
        $raw = $this->endpoint->postJson(self::PATH_EMBED, $body);
        $data = Json::decodeObject($raw, 'decode response');
        $embeddings = [];
        foreach (Arr::objects($data, 'embeddings') as $vector) {
            $embeddings[] = array_values(array_map(
                static fn(mixed $v): float => is_int($v) || is_float($v) ? (float) $v : 0.0,
                $vector,
            ));
        }
        $model = Arr::str($data, 'model');
        $prompt = Arr::int($data, 'prompt_eval_count');

        return new EmbedResponse(
            $embeddings,
            $model === '' ? $this->modelName : $model,
            new Usage(inputTokens: $prompt, totalTokens: $prompt),
            $raw,
        );
    }

    private static function normaliseHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return self::DEFAULT_HOST;
        }
        if (!str_contains($host, '://')) {
            $host = 'http://' . $host;
        }
        if (substr_count($host, '://') !== 1) {
            throw new InvalidRequestException(sprintf('%s: invalid host "%s"', self::ID, $host));
        }

        return rtrim($host, '/');
    }
}
