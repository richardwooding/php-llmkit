<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenAiCompat;

use Closure;
use LlmKit\ReasoningConfig;
use LlmKit\Request;

/** Quirks describe what a compatible endpoint accepts beyond plain text chat. */
final readonly class Quirks
{
    /**
     * @param string                                              $reasoningContentField assistant-message field carrying thinking text
     *                                                                                   ("reasoning_content" for DeepSeek, "reasoning" for Groq
     *                                                                                   and OpenRouter); '' means none
     * @param (Closure(ReasoningConfig): array<string,mixed>)|null $reasoningRequest      maps a ReasoningConfig onto request fields; null sends
     *                                                                                   "reasoning_effort" when an effort is set
     * @param string                                              $embedPath             embeddings endpoint ("/embeddings"); '' disables embed
     * @param (Closure(string, Request): void)|null              $validate              rejects requests the endpoint cannot serve for a model
     */
    public function __construct(
        public bool $images = false,
        public bool $audio = false,
        public bool $files = false,
        public string $reasoningContentField = '',
        public ?Closure $reasoningRequest = null,
        public bool $streamUsage = false,
        public bool $jsonSchema = false,
        public bool $strict = false,
        public bool $seed = false,
        public bool $maxCompletionTokens = false,
        public bool $developerRole = false,
        public string $embedPath = '',
        public ?Closure $validate = null,
    ) {}
}
