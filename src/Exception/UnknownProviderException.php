<?php

declare(strict_types=1);

namespace LlmKit\Exception;

use InvalidArgumentException;

/** UnknownProviderException is thrown when a model name resolves to nothing. */
final class UnknownProviderException extends InvalidArgumentException implements LlmKitException {}
