<?php

declare(strict_types=1);

namespace LlmKit\Providers\Voyage;

use LlmKit\Config;
use LlmKit\Embedder;
use LlmKit\EmbedRequest;
use LlmKit\EmbedResponse;
use LlmKit\Exception\InvalidRequestException;
use LlmKit\Exception\MissingApiKeyException;
use LlmKit\Exception\UnsupportedException;
use LlmKit\FilePart;
use LlmKit\Http\Endpoint;
use LlmKit\Http\Wire;
use LlmKit\ImagePart;
use LlmKit\Internal\Arr;
use LlmKit\Internal\Json;
use LlmKit\MultimodalEmbedder;
use LlmKit\MultimodalEmbedRequest;
use LlmKit\Part;
use LlmKit\Reranker;
use LlmKit\RerankRequest;
use LlmKit\RerankResponse;
use LlmKit\RerankResult;
use LlmKit\TextPart;
use LlmKit\Usage;

/**
 * VoyageClient embeds and reranks with one Voyage AI model. Voyage has no
 * chat models, so the client implements only the embedding and reranking
 * capabilities.
 */
final class VoyageClient implements Embedder, MultimodalEmbedder, Reranker
{
    /** ID is the provider identifier used in "voyage/<model>" names. */
    public const string ID = 'voyage';

    public const string BASE_URL = 'https://api.voyageai.com/v1';
    public const string API_KEY_ENV = 'VOYAGE_API_KEY';

    private const string PATH_EMBED = '/embeddings';
    private const string PATH_MULTIMODAL = '/multimodalembeddings';
    private const string PATH_RERANK = '/rerank';

    private function __construct(
        private readonly string $modelName,
        private readonly Endpoint $endpoint,
    ) {}

    /** create builds a client. The key comes from VOYAGE_API_KEY by default. */
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

    /** matchesModel claims model names starting with "voyage-". */
    public static function matchesModel(string $model): bool
    {
        return str_starts_with(strtolower($model), 'voyage-');
    }

    public function provider(): string
    {
        return self::ID;
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function embed(EmbedRequest $request): EmbedResponse
    {
        if ($request->inputs === []) {
            throw new InvalidRequestException(self::ID . ': no inputs');
        }
        $body = Wire::encode(Wire::filter([
            'model' => $this->modelName,
            'input' => $request->inputs,
            'input_type' => $request->inputType?->value,
            'output_dimension' => $request->dimensions > 0 ? $request->dimensions : null,
            'truncation' => true,
        ]), $request->providerExtra(self::ID));

        return $this->embeddings(self::PATH_EMBED, $body);
    }

    public function embedMultimodal(MultimodalEmbedRequest $request): EmbedResponse
    {
        if ($request->inputs === []) {
            throw new InvalidRequestException(self::ID . ': no inputs');
        }
        $inputs = [];
        foreach ($request->inputs as $parts) {
            $inputs[] = ['content' => self::multimodalContent($parts)];
        }
        $body = Wire::encode(Wire::filter([
            'model' => $this->modelName,
            'inputs' => $inputs,
            'input_type' => $request->inputType?->value,
            'truncation' => true,
        ]), $request->providerExtra(self::ID));

        return $this->embeddings(self::PATH_MULTIMODAL, $body);
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
            'top_k' => $request->topN > 0 ? $request->topN : null,
        ]), $request->providerExtra(self::ID));
        $raw = $this->endpoint->postJson(self::PATH_RERANK, $body);
        $data = Json::decodeObject($raw, 'decode response');
        $results = [];
        foreach (Arr::objects($data, 'data') as $row) {
            $results[] = new RerankResult(Arr::int($row, 'index'), Arr::float($row, 'relevance_score'));
        }
        $total = Arr::int(Arr::obj($data, 'usage'), 'total_tokens');
        $model = Arr::str($data, 'model');

        return new RerankResponse(
            $results,
            $model === '' ? $this->modelName : $model,
            new Usage(inputTokens: $total, totalTokens: $total),
            $raw,
        );
    }

    private function embeddings(string $path, string $body): EmbedResponse
    {
        $raw = $this->endpoint->postJson($path, $body);
        $data = Json::decodeObject($raw, 'decode response');
        $rows = Arr::objects($data, 'data');
        usort($rows, static fn(array $a, array $b): int => Arr::int($a, 'index') <=> Arr::int($b, 'index'));
        $embeddings = [];
        foreach ($rows as $row) {
            $embeddings[] = Arr::floats($row, 'embedding');
        }
        $total = Arr::int(Arr::obj($data, 'usage'), 'total_tokens');
        $model = Arr::str($data, 'model');

        return new EmbedResponse(
            $embeddings,
            $model === '' ? $this->modelName : $model,
            new Usage(inputTokens: $total, totalTokens: $total),
            $raw,
        );
    }

    /**
     * @param list<Part> $parts
     *
     * @return list<array<string,mixed>>
     */
    private static function multimodalContent(array $parts): array
    {
        $out = [];
        foreach ($parts as $part) {
            if ($part instanceof TextPart) {
                $out[] = ['type' => 'text', 'text' => $part->text];
                continue;
            }
            if ($part instanceof ImagePart) {
                $out[] = $part->url !== ''
                    ? ['type' => 'image_url', 'image_url' => $part->url]
                    : ['type' => 'image_base64', 'image_base64' => Wire::dataUri($part->mime, $part->data)];
                continue;
            }
            if ($part instanceof FilePart) {
                if (!str_starts_with(Wire::mimeOr($part->mime, $part->data), 'video/')) {
                    throw UnsupportedException::forProvider(self::ID, 'file input other than video');
                }
                $out[] = $part->url !== ''
                    ? ['type' => 'video_url', 'video_url' => $part->url]
                    : ['type' => 'video_base64', 'video_base64' => Wire::dataUri($part->mime, $part->data)];
                continue;
            }

            throw UnsupportedException::forProvider(
                self::ID,
                $part::class . ' in multimodal embedding',
            );
        }

        return $out;
    }
}
