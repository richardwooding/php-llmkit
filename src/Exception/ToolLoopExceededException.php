<?php

declare(strict_types=1);

namespace LlmKit\Exception;

use RuntimeException;

/** ToolLoopExceededException is thrown when Tools::run hits its iteration cap. */
final class ToolLoopExceededException extends RuntimeException implements LlmKitException {}
