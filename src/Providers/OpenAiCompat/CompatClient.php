<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenAiCompat;

use Generator;
use LlmKit\Chatter;
use LlmKit\Config;
use LlmKit\Embedder;
use LlmKit\EmbedRequest;
use LlmKit\EmbedResponse;
use LlmKit\Exception\InvalidRequestException;
use LlmKit\Exception\MissingApiKeyException;
use LlmKit\Exception\UnsupportedException;
use LlmKit\Http\Endpoint;
use LlmKit\Internal\Arr;
use LlmKit\Internal\Json;
use LlmKit\Request;
use LlmKit\Response;
use LlmKit\Streamer;
use LlmKit\Usage;

/**
 * CompatClient speaks Chat Completions to one endpoint for one model. It is
 * the transport the DeepSeek, Groq, x.ai, OpenRouter and Hugging Face
 * providers delegate to, and the one a custom endpoint registered with
 * CompatProvider uses.
 */
final class CompatClient implements Chatter, Streamer, Embedder
{
    private const string CHAT_PATH = '/chat/completions';

    private readonly CompatMapper $mapper;

    private function __construct(
        private readonly EndpointConfig $config,
        private readonly string $modelName,
        private readonly Endpoint $endpoint,
    ) {
        $this->mapper = new CompatMapper($config, $modelName);
    }

    /** create builds a client; no network I/O happens here. */
    public static function create(string $model, EndpointConfig $endpoint, ?Config $config = null): self
    {
        $config ??= new Config();
        $key = $config->apiKey ?? '';
        if ($key === '' && $endpoint->apiKeyEnv !== '') {
            $env = getenv($endpoint->apiKeyEnv);
            $key = is_string($env) ? $env : '';
        }
        if ($key === '' && !$endpoint->keyOptional) {
            throw MissingApiKeyException::forEnv($endpoint->id, $endpoint->apiKeyEnv);
        }
        $http = Endpoint::create($endpoint->id, $config, $endpoint->baseUrl);
        if ($key !== '') {
            $http = $http->withBearerAuth($key);
        }
        foreach ($endpoint->headers as $name => $value) {
            $http = $http->withDefaultHeader($name, $value);
        }

        return new self($endpoint, $model, $http);
    }

    public function provider(): string
    {
        return $this->config->id;
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function chat(Request $request): Response
    {
        $raw = $this->endpoint->postJson(self::CHAT_PATH, $this->mapper->body($request, false));

        return $this->mapper->toResponse(Json::decodeObject($raw, 'decode response'))->withRaw($raw);
    }

    public function stream(Request $request): Generator
    {
        $body = $this->mapper->body($request, true);
        $state = new CompatStreamState($this->mapper);
        foreach ($this->endpoint->postSse(self::CHAT_PATH, $body) as $event) {
            if (trim($event->data) === '') {
                continue;
            }
            foreach ($state->apply($event->data) as $chunk) {
                yield $chunk;
            }
        }
        yield $state->finish();
    }

    public function embed(EmbedRequest $request): EmbedResponse
    {
        $path = $this->config->quirks->embedPath;
        if ($path === '') {
            throw UnsupportedException::forProvider($this->config->id, 'embeddings');
        }
        if ($request->inputs === []) {
            throw new InvalidRequestException($this->config->id . ': no inputs');
        }
        $body = $this->mapper->embedBody(
            $this->modelName,
            $request->inputs,
            $request->dimensions,
            $request->providerExtra($this->config->id),
        );
        $raw = $this->endpoint->postJson($path, $body);
        $data = Json::decodeObject($raw, 'decode response');

        return self::toEmbedResponse($data, $this->modelName)->withRaw($raw);
    }

    /** @param array<string,mixed> $data */
    public static function toEmbedResponse(array $data, string $fallbackModel): EmbedResponse
    {
        $rows = Arr::objects($data, 'data');
        usort($rows, static fn(array $a, array $b): int => Arr::int($a, 'index') <=> Arr::int($b, 'index'));
        $embeddings = [];
        foreach ($rows as $row) {
            $embeddings[] = Arr::floats($row, 'embedding');
        }
        $usage = Arr::obj($data, 'usage');
        $model = Arr::str($data, 'model');

        return new EmbedResponse(
            $embeddings,
            $model === '' ? $fallbackModel : $model,
            $usage === [] ? new Usage() : CompatMapper::usage($usage),
        );
    }
}
