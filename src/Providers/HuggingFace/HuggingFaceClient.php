<?php

declare(strict_types=1);

namespace LlmKit\Providers\HuggingFace;

use LlmKit\Chatter;
use LlmKit\Config;
use LlmKit\Embedder;
use LlmKit\EmbedRequest;
use LlmKit\EmbedResponse;
use LlmKit\Exception\InvalidRequestException;
use LlmKit\Exception\TransportException;
use LlmKit\Http\Endpoint;
use LlmKit\Http\Wire;
use LlmKit\Internal\Json;
use LlmKit\Providers\OpenAiCompat\AbstractCompatBackedClient;
use LlmKit\Providers\OpenAiCompat\ChatsViaCompat;
use LlmKit\Providers\OpenAiCompat\CompatClient;
use LlmKit\Providers\OpenAiCompat\EndpointConfig;
use LlmKit\Providers\OpenAiCompat\Quirks;
use LlmKit\Providers\OpenAiCompat\StreamsViaCompat;
use LlmKit\Streamer;

/**
 * HuggingFaceClient talks to the Hugging Face Inference Providers router:
 * OpenAI-compatible chat plus feature-extraction embeddings.
 */
final class HuggingFaceClient extends AbstractCompatBackedClient implements Chatter, Embedder, Streamer
{
    use ChatsViaCompat;
    use StreamsViaCompat;

    /** ID is the provider identifier used in "huggingface/<model>" names; "hf" is an alias. */
    public const string ID = 'huggingface';

    public const string BASE_URL = 'https://router.huggingface.co/v1';
    public const string API_KEY_ENV = 'HF_TOKEN';

    private function __construct(string $id, CompatClient $inner, private readonly Endpoint $embedEndpoint)
    {
        parent::__construct($id, $inner);
    }

    /** create builds a client. The token comes from HF_TOKEN by default. */
    public static function create(string $model, ?Config $config = null): self
    {
        $config ??= new Config();
        $inner = CompatClient::create($model, self::endpoint(), $config);
        $key = $config->apiKey ?? '';
        if ($key === '') {
            $env = getenv(self::API_KEY_ENV);
            $key = is_string($env) ? $env : '';
        }
        $endpoint = Endpoint::create(self::ID, $config, self::BASE_URL)->withBearerAuth($key);

        return new self(self::ID, $inner, $endpoint);
    }

    /** endpoint returns the Hugging Face router endpoint definition. */
    public static function endpoint(): EndpointConfig
    {
        return new EndpointConfig(
            id: self::ID,
            baseUrl: self::BASE_URL,
            apiKeyEnv: self::API_KEY_ENV,
            match: static fn(string $model): bool => str_contains($model, '/') && !str_contains($model, ':'),
            quirks: new Quirks(images: true, streamUsage: true, jsonSchema: true),
        );
    }

    /**
     * embed calls the feature-extraction pipeline for the model and returns
     * one vector per input. Token-level outputs are mean-pooled.
     */
    public function embed(EmbedRequest $request): EmbedResponse
    {
        if ($request->inputs === []) {
            throw new InvalidRequestException(self::ID . ': no inputs');
        }
        $body = Wire::encode(['inputs' => $request->inputs], $request->providerExtra(self::ID));
        $raw = $this->embedEndpoint->postJson($this->embedPath(), $body);

        return new EmbedResponse(
            self::decodeVectors(Json::decode($raw, 'decode embeddings'), count($request->inputs)),
            $this->model(),
            raw: $raw,
        );
    }

    private function embedPath(): string
    {
        $base = $this->embedEndpoint->baseUrl;
        if (str_ends_with($base, '/v1')) {
            $base = substr($base, 0, -3);
        }

        return $base . '/hf-inference/models/' . $this->model() . '/pipeline/feature-extraction';
    }

    /** @return list<list<float>> */
    private static function decodeVectors(mixed $decoded, int $inputs): array
    {
        if (!is_array($decoded) || $decoded === []) {
            throw new TransportException(self::ID . ': unexpected embeddings shape');
        }
        $rows = array_values($decoded);
        if (self::isVector($rows[0])) {
            /** @var list<list<float>> $flat */
            $flat = array_map(self::vector(...), $rows);
            // A single input answered token by token: pool it into one vector.
            return $inputs === 1 && $flat[0] !== [] ? [self::meanPool($flat)] : $flat;
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new TransportException(self::ID . ': unexpected embeddings shape');
            }
            $tokens = [];
            foreach (array_values($row) as $token) {
                $tokens[] = self::vector($token);
            }
            $out[] = self::meanPool($tokens);
        }

        return $out;
    }

    private static function isVector(mixed $row): bool
    {
        if (!is_array($row) || $row === []) {
            return false;
        }
        $first = array_values($row)[0];

        return is_int($first) || is_float($first);
    }

    /** @return list<float> */
    private static function vector(mixed $row): array
    {
        if (!is_array($row)) {
            throw new TransportException(self::ID . ': unexpected embeddings shape');
        }
        $out = [];
        foreach ($row as $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new TransportException(self::ID . ': unexpected embeddings shape');
            }
            $out[] = (float) $value;
        }

        return $out;
    }

    /**
     * @param list<list<float>> $rows
     *
     * @return list<float>
     */
    private static function meanPool(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        if (count($rows) === 1) {
            return $rows[0];
        }
        $out = array_fill(0, count($rows[0]), 0.0);
        foreach ($rows as $row) {
            foreach ($out as $i => $sum) {
                $out[$i] = $sum + ($row[$i] ?? 0.0);
            }
        }
        $n = (float) count($rows);

        return array_map(static fn(float $sum): float => $sum / $n, $out);
    }
}
