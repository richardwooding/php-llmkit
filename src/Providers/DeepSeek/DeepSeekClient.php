<?php

declare(strict_types=1);

namespace LlmKit\Providers\DeepSeek;

use LlmKit\Chatter;
use LlmKit\Config;
use LlmKit\Exception\UnsupportedException;
use LlmKit\Providers\OpenAiCompat\AbstractCompatBackedClient;
use LlmKit\Providers\OpenAiCompat\ChatsViaCompat;
use LlmKit\Providers\OpenAiCompat\CompatClient;
use LlmKit\Providers\OpenAiCompat\EndpointConfig;
use LlmKit\Providers\OpenAiCompat\Quirks;
use LlmKit\Providers\OpenAiCompat\StreamsViaCompat;
use LlmKit\Request;
use LlmKit\Streamer;

/** DeepSeekClient talks to DeepSeek for one model. DeepSeek has no embeddings. */
final class DeepSeekClient extends AbstractCompatBackedClient implements Chatter, Streamer
{
    use ChatsViaCompat;
    use StreamsViaCompat;

    /** ID is the provider identifier used in "deepseek/<model>" names. */
    public const string ID = 'deepseek';

    public const string BASE_URL = 'https://api.deepseek.com';
    public const string API_KEY_ENV = 'DEEPSEEK_API_KEY';

    /** create builds a client. The key comes from DEEPSEEK_API_KEY by default. */
    public static function create(string $model, ?Config $config = null): self
    {
        return new self(self::ID, CompatClient::create($model, self::endpoint(), $config));
    }

    /** endpoint returns the DeepSeek endpoint definition. */
    public static function endpoint(): EndpointConfig
    {
        return new EndpointConfig(
            id: self::ID,
            baseUrl: self::BASE_URL,
            apiKeyEnv: self::API_KEY_ENV,
            match: EndpointConfig::prefixMatcher('deepseek-'),
            quirks: new Quirks(
                reasoningContentField: 'reasoning_content',
                // DeepSeek has no request-side reasoning switch.
                reasoningRequest: static fn(): array => [],
                streamUsage: true,
                validate: static function (string $model, Request $request): void {
                    // deepseek-reasoner rejects tool definitions; fail before the request is sent.
                    if (str_contains($model, 'reasoner') && $request->tools !== []) {
                        throw UnsupportedException::forProvider(self::ID, 'tools with ' . $model);
                    }
                },
            ),
        );
    }
}
