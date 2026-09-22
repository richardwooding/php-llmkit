<?php

declare(strict_types=1);

namespace LlmKit\Providers\Cohere;

use Generator;
use LlmKit\Chatter;
use LlmKit\Chunk;
use LlmKit\ChunkKind;
use LlmKit\Config;
use LlmKit\Embedder;
use LlmKit\EmbedRequest;
use LlmKit\EmbedResponse;
use LlmKit\Exception\InvalidRequestException;
use LlmKit\Exception\MissingApiKeyException;
use LlmKit\FinishReason;
use LlmKit\Http\Endpoint;
use LlmKit\Http\Wire;
use LlmKit\Internal\Arr;
use LlmKit\Internal\Json;
use LlmKit\Request;
use LlmKit\Reranker;
use LlmKit\RerankRequest;
use LlmKit\RerankResponse;
use LlmKit\RerankResult;
use LlmKit\Response;
use LlmKit\Streamer;
use LlmKit\ToolCallDelta;
use LlmKit\Usage;

/**
 * CohereClient talks to the Cohere v2 API: Command chat models with tools,
 * streaming and thinking, plus Embed and Rerank models.
 */
final class CohereClient implements Chatter, Embedder, Reranker, Streamer
{
    /** ID is the provider identifier used in "cohere/<model>" names. */
    public const string ID = 'cohere';

    public const string BASE_URL = 'https://api.cohere.com/v2';
    public const string API_KEY_ENV = 'COHERE_API_KEY';

    private const string PATH_CHAT = '/chat';
    private const string PATH_EMBED = '/embed';
    private const string PATH_RERANK = '/rerank';

    /** @var list<string> */
    private const array MODEL_PREFIXES = ['command', 'embed-', 'rerank-', 'c4ai-', 'aya-'];

    private readonly CohereMapper $mapper;

    private function __construct(
        private readonly string $modelName,
        private readonly Endpoint $endpoint,
    ) {
        $this->mapper = new CohereMapper($modelName);
    }

    /** create builds a client. The key comes from COHERE_API_KEY by default. */
    public static function create(string $model, ?Config $config = null): self
    {
        $config ??= new Config();
        $key = $config->apiKey ?? '';
        if ($key === '') {
            $env = getenv(self::API_KEY_ENV);
            $key = is_string($env) ? $env : '';
        }
        if ($key === '') {
            throw MissingApiKeyException::forEnv(self::ID, self::API_KEY_ENV);
        }

        return new self($model, Endpoint::create(self::ID, $config, self::BASE_URL)->withBearerAuth($key));
    }

    /** matchesModel claims Command, Embed, Rerank, C4AI and Aya model names. */
    public static function matchesModel(string $model): bool
    {
        $model = strtolower($model);
        foreach (self::MODEL_PREFIXES as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }

        return false;
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
        $finish = Chunk::finish(FinishReason::Other);
        foreach ($this->endpoint->postSse(self::PATH_CHAT, $body) as $event) {
            if (trim($event->data) === '') {
                continue;
            }
            $frame = Json::decodeObject($event->data, 'decode stream event');
            if (Arr::str($frame, 'type') === 'message-end') {
                $finish = self::finishChunk($frame, $event->data);
                continue;
            }
            $chunk = self::chunk($frame, $event->data);
            if ($chunk !== null) {
                yield $chunk;
            }
        }
        yield $finish;
    }

    public function embed(EmbedRequest $request): EmbedResponse
    {
        if ($request->inputs === []) {
            throw new InvalidRequestException(self::ID . ': no inputs');
        }
        $body = $this->mapper->embedBody(
            $request->inputs,
            $request->inputType,
            $request->dimensions,
            $request->providerExtra(self::ID),
        );
        $raw = $this->endpoint->postJson(self::PATH_EMBED, $body);
        $data = Json::decodeObject($raw, 'decode response');
        $embeddings = [];
        foreach (Arr::objects(Arr::obj($data, 'embeddings'), 'float') as $vector) {
            $embeddings[] = array_values(array_map(
                static fn(mixed $v): float => is_int($v) || is_float($v) ? (float) $v : 0.0,
                $vector,
            ));
        }
        $meta = Arr::obj($data, 'meta');
        $tokens = Arr::obj($meta, 'billed_units');
        if ($tokens === []) {
            $tokens = Arr::obj($meta, 'tokens');
        }
        $input = (int) Arr::float($tokens, 'input_tokens');

        return new EmbedResponse(
            $embeddings,
            $this->modelName,
            new Usage(inputTokens: $input, totalTokens: $input),
            $raw,
        );
    }

    public function rerank(RerankRequest $request): RerankResponse
    {
        if ($request->query === '' || $request->documents === []) {
            throw new InvalidRequestException(self::ID . ': rerank needs a query and documents');
        }
        $body = Wire::encode(Wire::filter([
            'model' => $this->modelName,
            'query' => $request->query,
            'documents' => $request->documents,
            'top_n' => $request->topN > 0 ? $request->topN : null,
        ]), $request->providerExtra(self::ID));
        $raw = $this->endpoint->postJson(self::PATH_RERANK, $body);
        $data = Json::decodeObject($raw, 'decode response');
        $results = [];
        foreach (Arr::objects($data, 'results') as $result) {
            $results[] = new RerankResult(
                Arr::int($result, 'index'),
                Arr::float($result, 'relevance_score'),
            );
        }

        return new RerankResponse($results, $this->modelName, raw: $raw);
    }

    /** @param array<string,mixed> $event */
    private static function chunk(array $event, string $raw): ?Chunk
    {
        $message = Arr::obj(Arr::obj($event, 'delta'), 'message');
        $content = Arr::obj($message, 'content');

        switch (Arr::str($event, 'type')) {
            case 'content-delta':
                if (Arr::str($content, 'thinking') !== '') {
                    return new Chunk(ChunkKind::Reasoning, text: Arr::str($content, 'thinking'), raw: $raw);
                }
                if (Arr::str($content, 'text') !== '') {
                    return Chunk::text(Arr::str($content, 'text'), $raw);
                }

                return null;
            case 'tool-plan-delta':
                $plan = Arr::str($message, 'tool_plan');

                return $plan === '' ? null : new Chunk(ChunkKind::Reasoning, text: $plan, raw: $raw);
            case 'tool-call-start':
            case 'tool-call-delta':
                $call = Arr::obj($message, 'tool_calls');
                $function = Arr::obj($call, 'function');

                return Chunk::toolCall(new ToolCallDelta(
                    index: Arr::int($event, 'index'),
                    id: Arr::str($call, 'id'),
                    name: Arr::str($function, 'name'),
                    arguments: Arr::str($function, 'arguments'),
                ), $raw);
            default:
                return null;
        }
    }

    /** @param array<string,mixed> $event */
    private static function finishChunk(array $event, string $raw): Chunk
    {
        $delta = Arr::obj($event, 'delta');
        $usage = Arr::obj($delta, 'usage');

        return Chunk::finish(
            CohereMapper::finishReason(Arr::str($delta, 'finish_reason')),
            $usage === [] ? null : CohereMapper::usage($usage),
            $raw,
        );
    }
}
