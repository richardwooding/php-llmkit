<?php

declare(strict_types=1);

namespace LlmKit;

/**
 * Provider is implemented by each backend and registered with a Registry.
 * open must not perform network I/O; reading environment variables is
 * allowed.
 */
interface Provider
{
    /** id returns the provider identifier used in "<id>/<model>" names. */
    public function id(): string;

    /** matches reports whether a bare model name belongs to this provider. */
    public function matches(string $bareModel): bool;

    /** open builds a client for $model. */
    public function open(string $model, Config $config): Client;
}
