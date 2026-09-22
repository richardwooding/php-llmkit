<?php

declare(strict_types=1);

namespace LlmKit;

use LlmKit\Internal\Json;

/** ToolCall is a request from the model to invoke a tool. */
final readonly class ToolCall implements Part
{
    /** @param string $arguments raw JSON object produced by the model */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $arguments = '',
    ) {}

    /**
     * arguments decodes the call's JSON arguments; an empty payload is {}.
     *
     * @return array<string,mixed>
     */
    public function argumentsArray(): array
    {
        if (trim($this->arguments) === '') {
            return [];
        }

        return Json::decodeObject($this->arguments, 'decode tool call arguments');
    }

    /** argumentsJson returns the arguments as a JSON object, never empty. */
    public function argumentsJson(): string
    {
        return trim($this->arguments) === '' ? '{}' : $this->arguments;
    }
}
