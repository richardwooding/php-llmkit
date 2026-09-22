<?php

declare(strict_types=1);

namespace LlmKit\Providers\Vertex;

use Closure;
use Generator;
use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\FetchAuthTokenInterface;
use LlmKit\Chatter;
use LlmKit\Chunk;
use LlmKit\ChunkKind;
use LlmKit\Config;
use LlmKit\Embedder;
use LlmKit\EmbedRequest;
use LlmKit\EmbedResponse;
use LlmKit\Exception\ApiException;
use LlmKit\Exception\InvalidRequestException;
use LlmKit\Exception\MissingApiKeyException;
use LlmKit\FinishReason;
use LlmKit\Http\Endpoint;
use LlmKit\Internal\Arr;
use LlmKit\Internal\Json;
use LlmKit\ReasoningDelta;
use LlmKit\Request;
use LlmKit\Response;
use LlmKit\Streamer;
use LlmKit\ToolCallDelta;
use LlmKit\Usage;

/**
 * VertexClient talks to Gemini and Google embedding models on Vertex AI over
 * REST.
 *
 * Credentials are resolved on the first request, never at construction: an
 * access token from OPTION_ACCESS_TOKEN or Config::withApiKey, a callable from
 * OPTION_TOKEN_PROVIDER, or Application Default Credentials through the
 * optional google/auth package.
 */
final class VertexClient implements Chatter, Embedder, Streamer
{
    /** ID is the provider identifier used in "vertex/<model>" names. */
    public const string ID = 'vertex';

    /** DEFAULT_LOCATION is used when no location is configured. */
    public const string DEFAULT_LOCATION = 'us-central1';

    /** OPTION_PROJECT sets the Google Cloud project, overriding GOOGLE_CLOUD_PROJECT. */
    public const string OPTION_PROJECT = 'vertex.project';

    /** OPTION_LOCATION sets the region, overriding GOOGLE_CLOUD_LOCATION. */
    public const string OPTION_LOCATION = 'vertex.location';

    /** OPTION_ACCESS_TOKEN uses a fixed bearer token, handy for CI and tests. */
    public const string OPTION_ACCESS_TOKEN = 'vertex.access_token';

    /** OPTION_TOKEN_PROVIDER supplies a callable returning a fresh access token. */
    public const string OPTION_TOKEN_PROVIDER = 'vertex.token_provider';

    private const string SCOPE = 'https://www.googleapis.com/auth/cloud-platform';
    private const string LOCATION_GLOBAL = 'global';

    /** @var list<string> */
    private const array MODEL_PREFIXES = [
        'gemini-', 'imagen-', 'text-embedding-0', 'text-multilingual-embedding-', 'gemini-embedding-',
    ];

    private readonly VertexMapper $mapper;

    private function __construct(
        private readonly string $modelName,
        private readonly string $project,
        private readonly string $location,
        private readonly Endpoint $endpoint,
    ) {
        $this->mapper = new VertexMapper($modelName);
    }

    /**
     * create builds a client. The project comes from GOOGLE_CLOUD_PROJECT and
     * the location from GOOGLE_CLOUD_LOCATION unless configured. No network
     * I/O happens here.
     */
    public static function create(string $model, ?Config $config = null): self
    {
        $config ??= new Config();
        $project = $config->stringValue(self::OPTION_PROJECT);
        if ($project === '') {
            $project = self::env('GOOGLE_CLOUD_PROJECT');
        }
        if ($project === '') {
            $project = self::env('GCLOUD_PROJECT');
        }
        if ($project === '') {
            throw new MissingApiKeyException(
                self::ID . ': set GOOGLE_CLOUD_PROJECT or Config::withValue(VertexClient::OPTION_PROJECT, ...)',
            );
        }
        $location = $config->stringValue(self::OPTION_LOCATION);
        if ($location === '') {
            $location = self::env('GOOGLE_CLOUD_LOCATION');
        }
        if ($location === '') {
            $location = self::DEFAULT_LOCATION;
        }
        $endpoint = Endpoint::create(self::ID, $config, self::hostFor($location))
            ->withAuth(self::authenticator($config));

        return new self($model, $project, $location, $endpoint);
    }

    /** matchesModel claims Google publisher model names (gemini-*, imagen-*, text-embedding-*). */
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
        $body = $this->mapper->body($request);

        try {
            $raw = $this->endpoint->postJson($this->modelPath('generateContent'), $body);
        } catch (ApiException $e) {
            throw self::googleError($e);
        }

        return $this->mapper->toResponse(Json::decodeObject($raw, 'decode response'))->withRaw($raw);
    }

    public function stream(Request $request): Generator
    {
        $body = $this->mapper->body($request);
        $path = $this->modelPath('streamGenerateContent') . '?alt=sse';
        $finishReason = '';
        $blocked = false;
        $usage = null;
        $calls = 0;
        // A thoughtSignature closes a reasoning block, so thought text streamed
        // before it reassembles into one part.
        $reason = 0;

        foreach ($this->sse($path, $body) as $event) {
            if (trim($event->data) === '') {
                continue;
            }
            $frame = Json::decodeObject($event->data, 'decode stream chunk');
            if (Arr::obj($frame, 'error') !== []) {
                throw self::streamError($frame, $event->data);
            }
            $usageData = Arr::obj($frame, 'usageMetadata');
            if ($usageData !== []) {
                $usage = VertexMapper::usage($usageData);
            }
            $blocked = $blocked || VertexMapper::isBlocked($frame);
            $candidates = Arr::objects($frame, 'candidates');
            if ($candidates === []) {
                continue;
            }
            $candidate = $candidates[0];
            $reasonName = Arr::str($candidate, 'finishReason');
            if ($reasonName !== '') {
                $finishReason = $reasonName;
            }
            foreach (Arr::objects(Arr::obj($candidate, 'content'), 'parts') as $part) {
                $signature = Arr::str($part, 'thoughtSignature');
                $text = Arr::str($part, 'text');
                if (Arr::bool($part, 'thought')) {
                    yield Chunk::reasoning(
                        new ReasoningDelta(index: $reason, text: $text, signature: $signature),
                        $event->data,
                    );
                    if ($signature !== '') {
                        ++$reason;
                    }
                    continue;
                }
                $call = Arr::obj($part, 'functionCall');
                if ($call === [] && $text === '') {
                    continue;
                }
                if ($signature !== '') {
                    // Mirror the non-streaming mapper: emit the bare signature first.
                    yield new Chunk(ChunkKind::Reasoning, raw: $event->data, reasoning: new ReasoningDelta(
                        index: $reason,
                        signature: $signature,
                    ));
                    ++$reason;
                }
                if ($call !== []) {
                    $index = $calls;
                    ++$calls;
                    yield Chunk::toolCall(new ToolCallDelta(
                        index: $index,
                        id: VertexMapper::callId($calls),
                        name: Arr::str($call, 'name'),
                        arguments: VertexMapper::rawArguments($call['args'] ?? null),
                    ), $event->data);
                    continue;
                }
                yield Chunk::text($text, $event->data);
            }
        }

        $finish = VertexMapper::finishReason($finishReason, $calls > 0);
        if ($finishReason === '' && $blocked) {
            $finish = FinishReason::ContentFilter;
        }
        yield Chunk::finish($finish, $usage);
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

        try {
            $raw = $this->endpoint->postJson($this->modelPath('predict'), $body);
        } catch (ApiException $e) {
            throw self::googleError($e);
        }
        $data = Json::decodeObject($raw, 'decode response');
        $embeddings = [];
        $tokens = 0;
        foreach (Arr::objects($data, 'predictions') as $prediction) {
            $embedding = Arr::obj($prediction, 'embeddings');
            $embeddings[] = Arr::floats($embedding, 'values');
            $tokens += Arr::int(Arr::obj($embedding, 'statistics'), 'token_count');
        }

        return new EmbedResponse(
            $embeddings,
            $this->modelName,
            new Usage(inputTokens: $tokens, totalTokens: $tokens),
            $raw,
        );
    }

    /**
     * @return Generator<int,\LlmKit\Http\Event,mixed,void>
     */
    private function sse(string $path, string $body): Generator
    {
        try {
            yield from $this->endpoint->postSse($path, $body);
        } catch (ApiException $e) {
            throw self::googleError($e);
        }
    }

    private function modelPath(string $verb): string
    {
        return sprintf(
            '/v1/projects/%s/locations/%s/publishers/google/models/%s:%s',
            $this->project,
            $this->location,
            $this->modelName,
            $verb,
        );
    }

    private static function hostFor(string $location): string
    {
        return $location === self::LOCATION_GLOBAL
            ? 'https://aiplatform.googleapis.com'
            : 'https://' . $location . '-aiplatform.googleapis.com';
    }

    /**
     * authenticator defers credential discovery to the first request, so
     * construction never touches the network.
     *
     * @return Closure(): array<string,string>
     */
    private static function authenticator(Config $config): Closure
    {
        $token = $config->stringValue(self::OPTION_ACCESS_TOKEN);
        if ($token === '' && $config->apiKey !== null) {
            $token = $config->apiKey;
        }
        if ($token !== '') {
            return static fn(): array => ['Authorization' => 'Bearer ' . $token];
        }
        $provider = $config->value(self::OPTION_TOKEN_PROVIDER);
        if (is_callable($provider)) {
            return static function () use ($provider): array {
                $fresh = $provider();

                return ['Authorization' => 'Bearer ' . (is_string($fresh) ? $fresh : '')];
            };
        }

        return static fn(): array => self::adcHeaders();
    }

    /**
     * adcHeaders resolves Application Default Credentials once and refreshes
     * the access token as needed.
     *
     * @return array<string,string>
     */
    private static function adcHeaders(): array
    {
        /** @var FetchAuthTokenInterface|null $credentials */
        static $credentials = null;

        if (!class_exists(ApplicationDefaultCredentials::class)) {
            throw new MissingApiKeyException(
                self::ID . ': install google/auth for Application Default Credentials, '
                . 'or set Config::withValue(VertexClient::OPTION_ACCESS_TOKEN, ...)',
            );
        }
        $credentials ??= ApplicationDefaultCredentials::getCredentials(self::SCOPE);
        $token = $credentials->fetchAuthToken();
        $value = $token['access_token'] ?? null;
        if (!is_string($value) || $value === '') {
            throw new MissingApiKeyException(self::ID . ': application default credentials returned no token');
        }

        return ['Authorization' => 'Bearer ' . $value];
    }

    /**
     * googleError swaps the numeric code for the more useful status string
     * (RESOURCE_EXHAUSTED, ...).
     */
    private static function googleError(ApiException $error): ApiException
    {
        $body = Json::tryDecodeObject($error->body);
        $status = $body === null ? '' : Arr::str(Arr::obj($body, 'error'), 'status');
        if ($status === '') {
            return $error;
        }

        return ApiException::create(
            provider: $error->provider,
            status: $error->status,
            errorCode: $status,
            type: $error->type,
            detail: $error->detail,
            retryAfter: $error->retryAfter,
            body: $error->body,
        );
    }

    /**
     * streamError turns an error object that arrives mid-stream into an
     * exception, since the HTTP status was already 200.
     *
     * @param array<string,mixed> $frame
     */
    private static function streamError(array $frame, string $raw): ApiException
    {
        $error = Arr::obj($frame, 'error');

        return self::googleError(ApiException::create(
            provider: self::ID,
            status: Arr::int($error, 'code'),
            errorCode: Arr::str($error, 'status'),
            detail: Arr::str($error, 'message', 'stream error'),
            body: $raw,
        ));
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? $value : '';
    }
}
