<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenAi;

use Generator;
use LlmKit\Chatter;
use LlmKit\Chunk;
use LlmKit\ChunkKind;
use LlmKit\Config;
use LlmKit\Embedder;
use LlmKit\EmbedRequest;
use LlmKit\EmbedResponse;
use LlmKit\Exception\MissingApiKeyException;
use LlmKit\FinishReason;
use LlmKit\Http\Endpoint;
use LlmKit\Internal\Json;
use LlmKit\Providers\OpenAiCompat\CompatClient;
use LlmKit\Providers\OpenAiCompat\EndpointConfig;
use LlmKit\Providers\OpenAiCompat\Quirks;
use LlmKit\Request;
use LlmKit\Response;
use LlmKit\Streamer;

/**
 * OpenAiClient talks to the OpenAI platform: chat over the Responses API
 * (POST /responses) and vectors over POST /embeddings.
 *
 * Request::$stop and Request::$seed have no Responses API equivalent and are
 * ignored. ReasoningPart::$signature carries the reasoning item id and
 * ReasoningPart::$encrypted its encrypted_content; both are echoed back on
 * later turns so multi-turn tool use works with reasoning models.
 */
final class OpenAiClient implements Chatter, Embedder, Streamer
{
    /** ID is the provider identifier used in "openai/<model>" names. */
    public const string ID = 'openai';

    public const string BASE_URL = 'https://api.openai.com/v1';
    public const string API_KEY_ENV = 'OPENAI_API_KEY';

    /** HEADER_ORGANIZATION selects the OpenAI organisation to bill. */
    public const string HEADER_ORGANIZATION = 'OpenAI-Organization';

    /** HEADER_PROJECT selects the OpenAI project to bill. */
    public const string HEADER_PROJECT = 'OpenAI-Project';

    private const string PATH_RESPONSES = '/responses';
    private const string PATH_EMBED = '/embeddings';

    /** @var list<string> */
    private const array MODEL_PREFIXES = [
        'gpt', 'chatgpt-', 'o1', 'o3', 'o4', 'text-embedding-3', 'text-embedding-ada',
    ];

    private readonly OpenAiMapper $mapper;

    private function __construct(
        private readonly string $modelName,
        private readonly Endpoint $endpoint,
        private readonly CompatClient $embedder,
    ) {
        $this->mapper = new OpenAiMapper($modelName);
    }

    /** create builds a client. The key comes from OPENAI_API_KEY by default. */
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
        $endpoint = Endpoint::create(self::ID, $config, self::BASE_URL)->withBearerAuth($key);
        $embedder = CompatClient::create($model, new EndpointConfig(
            id: self::ID,
            baseUrl: self::BASE_URL,
            apiKeyEnv: self::API_KEY_ENV,
            quirks: new Quirks(embedPath: self::PATH_EMBED),
        ), $config);

        return new self($model, $endpoint, $embedder);
    }

    /** matchesModel claims gpt*, chatgpt-*, o1/o3/o4* and text-embedding-* names. */
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
        $raw = $this->endpoint->postJson(self::PATH_RESPONSES, $this->mapper->body($request, false));

        return $this->mapper->toResponse(Json::decodeObject($raw, 'decode response'), $raw)->withRaw($raw);
    }

    public function stream(Request $request): Generator
    {
        $body = $this->mapper->body($request, true);
        $state = new OpenAiStreamState();
        foreach ($this->endpoint->postSse(self::PATH_RESPONSES, $body) as $event) {
            if (trim($event->data) === '') {
                continue;
            }
            $chunk = $state->apply($event->data);
            if ($chunk === null) {
                continue;
            }
            yield $chunk;
            if ($state->isDone()) {
                return;
            }
        }
        // The server closed the stream without a terminal event.
        yield new Chunk(ChunkKind::Finish, finishReason: FinishReason::Other);
    }

    public function embed(EmbedRequest $request): EmbedResponse
    {
        return $this->embedder->embed($request);
    }
}
