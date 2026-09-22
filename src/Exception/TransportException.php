<?php

declare(strict_types=1);

namespace LlmKit\Exception;

use RuntimeException;

/**
 * TransportException covers failures below the API: connection errors,
 * malformed JSON and broken streams.
 */
final class TransportException extends RuntimeException implements LlmKitException {}
