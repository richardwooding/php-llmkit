<?php

declare(strict_types=1);

namespace LlmKit\Providers\Anthropic;

use Generator;
use LlmKit\Chatter;
use LlmKit\Config;
use LlmKit\Exception\MissingApiKeyException;
use LlmKit\Http\Endpoint;
use LlmKit\Internal\Arr;
use LlmKit\Internal\Json;
use LlmKit\Request;
use LlmKit\Response;
use LlmKit\Streamer;
use LlmKit\TokenCounter;

/**
 * AnthropicClient talks to the Claude Messages API. It chats, streams and
 * counts tokens; Anthropic offers no embeddings endpoint.
 *
 * max_tokens defaults to DEFAULT_MAX_TOKENS because thinking counts against
 * it. ReasoningPart::$signature carries a thinking block's signature and
 * ReasoningPart::$encrypted a redacted block's data; both are echoed back on
 * later turns, which the API requires when tools and thinking are combined.
 */
final class AnthropicClient implements Chatter, Streamer, TokenCounter
{
    /** ID is the provider identifier used in "anthropic/<model>" names. */
    public const string ID = 'anthropic';

    public const string BASE_URL = 'https://api.anthropic.com/v1';
    public const string API_KEY_ENV = 'ANTHROPIC_API_KEY';

    /** DEFAULT_VERSION is the anthropic-version header sent unless overridden. */
    public const string DEFAULT_VERSION = '2023-06-01';

    /** DEFAULT_MAX_TOKENS leaves room for thinking plus a long answer. */
    public const int DEFAULT_MAX_TOKENS = 16384;

    /** HEADER_VERSION pins the API version. */
    public const string HEADER_VERSION = 'anthropic-version';

    /** HEADER_BETA opts into beta features, comma separated. */
    public const string HEADER_BETA = 'anthropic-beta';

    private const string HEADER_API_KEY = 'x-api-key';
    private const string PATH_MESSAGES = '/messages';
    private const string PATH_COUNT_TOKENS = '/messages/count_tokens';

    private readonly AnthropicMapper $mapper;

    private function __construct(
        private readonly string $modelName,
        private readonly Endpoint $endpoint,
    ) {
        $this->mapper = new AnthropicMapper($modelName, self::DEFAULT_MAX_TOKENS);
    }

    /** create builds a client. The key comes from ANTHROPIC_API_KEY by default. */
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
        $endpoint = Endpoint::create(self::ID, $config, self::BASE_URL)
            ->withDefaultHeader(self::HEADER_VERSION, self::DEFAULT_VERSION)
            ->withHeaderAuth(self::HEADER_API_KEY, $key);

        return new self($model, $endpoint);
    }

    /** matchesModel claims model names starting with "claude". */
    public static function matchesModel(string $model): bool
    {
        return str_starts_with(strtolower($model), 'claude');
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
        $raw = $this->endpoint->postJson(self::PATH_MESSAGES, $this->mapper->body($request, false));

        return $this->mapper->toResponse(Json::decodeObject($raw, 'decode response'))->withRaw($raw);
    }

    public function stream(Request $request): Generator
    {
        $body = $this->mapper->body($request, true);
        $state = new AnthropicStreamState();
        foreach ($this->endpoint->postSse(self::PATH_MESSAGES, $body) as $event) {
            if (trim($event->data) === '') {
                continue;
            }
            foreach ($state->apply($event->data) as $chunk) {
                yield $chunk;
            }
            if ($state->isDone()) {
                return;
            }
        }
        yield $state->finish();
    }

    /**
     * countTokens asks POST /messages/count_tokens how many input tokens the
     * request would consume. Request::$extra and provider options are not
     * sent.
     */
    public function countTokens(Request $request): int
    {
        $raw = $this->endpoint->postJson(self::PATH_COUNT_TOKENS, $this->mapper->countBody($request));

        return Arr::int(Json::decodeObject($raw, 'decode response'), 'input_tokens');
    }
}
