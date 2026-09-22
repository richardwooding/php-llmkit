<?php

declare(strict_types=1);

namespace LlmKit\Exception;

use InvalidArgumentException;

/** InvalidRequestException is thrown for input this library rejects itself. */
final class InvalidRequestException extends InvalidArgumentException implements LlmKitException {}
