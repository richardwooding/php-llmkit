<?php

declare(strict_types=1);

namespace LlmKit\Http;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use LlmKit\Config;
use LlmKit\Exception\TransportException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * TransportFactory picks the transport a Config asks for: an explicit
 * Transport, an explicit PSR-18 client, then symfony/http-client, then
 * whatever php-http/discovery finds.
 *
 * @internal
 */
final class TransportFactory
{
    private static ?Transport $default = null;

    public static function resolve(Config $config): Transport
    {
        if ($config->transport !== null) {
            return $config->transport;
        }
        if ($config->httpClient !== null) {
            return self::psr18($config->httpClient, $config->requestFactory, $config->streamFactory);
        }

        return self::$default ??= self::discover();
    }

    /** setDefault overrides the transport used when a Config names none. */
    public static function setDefault(?Transport $transport): void
    {
        self::$default = $transport;
    }

    private static function discover(): Transport
    {
        if (SymfonyTransport::isAvailable()) {
            return SymfonyTransport::create();
        }
        if (class_exists(Psr18ClientDiscovery::class)) {
            return self::psr18(Psr18ClientDiscovery::find(), null, null);
        }

        throw new TransportException(
            'llmkit: no HTTP transport available: install symfony/http-client, '
            . 'or pass one with Config::withTransport() or Config::withHttpClient()',
        );
    }

    private static function psr18(
        ClientInterface $client,
        ?RequestFactoryInterface $requestFactory,
        ?StreamFactoryInterface $streamFactory,
    ): Transport {
        if ($requestFactory === null || $streamFactory === null) {
            if (!class_exists(Psr17FactoryDiscovery::class)) {
                throw new TransportException(
                    'llmkit: PSR-17 factories are required: pass them to Config::withHttpClient() '
                    . 'or install php-http/discovery',
                );
            }
            $requestFactory ??= Psr17FactoryDiscovery::findRequestFactory();
            $streamFactory ??= Psr17FactoryDiscovery::findStreamFactory();
        }

        return new Psr18Transport($client, $requestFactory, $streamFactory);
    }
}
