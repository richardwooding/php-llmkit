<?php

declare(strict_types=1);

namespace LlmKit;

/**
 * Part is one piece of message content. The implementations are TextPart,
 * ImagePart, AudioPart, FilePart, ReasoningPart, ToolCall and ToolResult;
 * providers reject the ones their API cannot carry.
 */
interface Part {}
