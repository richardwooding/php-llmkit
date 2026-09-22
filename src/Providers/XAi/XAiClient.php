<?php

declare(strict_types=1);

namespace LlmKit\Providers\XAi;

use LlmKit\Chatter;
use LlmKit\Config;
use LlmKit\Providers\OpenAiCompat\AbstractCompatBackedClient;
use LlmKit\Providers\OpenAiCompat\ChatsViaCompat;
use LlmKit\Providers\OpenAiCompat\CompatClient;
use LlmKit\Providers\OpenAiCompat\EndpointConfig;
use LlmKit\Providers\OpenAiCompat\Quirks;
use LlmKit\Providers\OpenAiCompat\StreamsViaCompat;
use LlmKit\Streamer;

/** XAiClient talks to x.ai (Grok) for one model. x.ai has no embeddings. */
final class XAiClient extends AbstractCompatBackedClient implements Chatter, Streamer
{
    use ChatsViaCompat;
    use StreamsViaCompat;

    /** ID is the provider identifier used in "xai/<model>" names. */
    public const string ID = 'xai';

    public const string BASE_URL = 'https://api.x.ai/v1';
    public const string API_KEY_ENV = 'XAI_API_KEY';

    /** create builds a client. The key comes from XAI_API_KEY by default. */
    public static function create(string $model, ?Config $config = null): self
    {
        return new self(self::ID, CompatClient::create($model, self::endpoint(), $config));
    }

    /** endpoint returns the x.ai endpoint definition. */
    public static function endpoint(): EndpointConfig
    {
        return new EndpointConfig(
            id: self::ID,
            baseUrl: self::BASE_URL,
            apiKeyEnv: self::API_KEY_ENV,
            match: EndpointConfig::prefixMatcher('grok-'),
            quirks: new Quirks(
                images: true,
                reasoningContentField: 'reasoning_content',
                streamUsage: true,
                jsonSchema: true,
                seed: true,
            ),
        );
    }
}
