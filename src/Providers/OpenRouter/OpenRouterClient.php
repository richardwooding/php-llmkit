<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenRouter;

use LlmKit\Chatter;
use LlmKit\Config;
use LlmKit\Embedder;
use LlmKit\Providers\OpenAiCompat\AbstractCompatBackedClient;
use LlmKit\Providers\OpenAiCompat\ChatsViaCompat;
use LlmKit\Providers\OpenAiCompat\CompatClient;
use LlmKit\Providers\OpenAiCompat\EmbedsViaCompat;
use LlmKit\Providers\OpenAiCompat\EndpointConfig;
use LlmKit\Providers\OpenAiCompat\Quirks;
use LlmKit\Providers\OpenAiCompat\StreamsViaCompat;
use LlmKit\ReasoningConfig;
use LlmKit\Streamer;

/**
 * OpenRouterClient talks to OpenRouter for one model. OpenRouter never claims
 * bare model names, so use "openrouter/<vendor>/<model>".
 *
 * Set HEADER_REFERER and HEADER_TITLE with Config::withHeader for the
 * attribution OpenRouter shows in its dashboard.
 */
final class OpenRouterClient extends AbstractCompatBackedClient implements Chatter, Embedder, Streamer
{
    use ChatsViaCompat;
    use EmbedsViaCompat;
    use StreamsViaCompat;

    /** ID is the provider identifier used in "openrouter/<model>" names. */
    public const string ID = 'openrouter';

    public const string BASE_URL = 'https://openrouter.ai/api/v1';
    public const string API_KEY_ENV = 'OPENROUTER_API_KEY';

    /** HEADER_REFERER carries the app URL OpenRouter attributes calls to. */
    public const string HEADER_REFERER = 'HTTP-Referer';

    /** HEADER_TITLE carries the app name OpenRouter shows in its dashboard. */
    public const string HEADER_TITLE = 'X-Title';

    /** create builds a client. The key comes from OPENROUTER_API_KEY by default. */
    public static function create(string $model, ?Config $config = null): self
    {
        return new self(self::ID, CompatClient::create($model, self::endpoint(), $config));
    }

    /** endpoint returns the OpenRouter endpoint definition. */
    public static function endpoint(): EndpointConfig
    {
        return new EndpointConfig(
            id: self::ID,
            baseUrl: self::BASE_URL,
            apiKeyEnv: self::API_KEY_ENV,
            quirks: new Quirks(
                images: true,
                audio: true,
                files: true,
                reasoningContentField: 'reasoning',
                reasoningRequest: static function (ReasoningConfig $reasoning): array {
                    $inner = [];
                    if ($reasoning->effort !== '') {
                        $inner['effort'] = $reasoning->effort;
                    }
                    if ($reasoning->budgetTokens > 0) {
                        $inner['max_tokens'] = $reasoning->budgetTokens;
                    }

                    return $inner === [] ? [] : ['reasoning' => $inner];
                },
                streamUsage: true,
                jsonSchema: true,
                seed: true,
                embedPath: '/embeddings',
            ),
        );
    }
}
