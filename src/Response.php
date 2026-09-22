<?php

declare(strict_types=1);

namespace LlmKit;

use JsonSerializable;

/**
 * Response is a completed assistant turn. The message parts are in provider
 * order and may mix TextPart, ReasoningPart and ToolCall.
 */
final readonly class Response implements JsonSerializable
{
    /** @param string|null $raw the provider's response body, verbatim */
    public function __construct(
        public Message $message,
        public FinishReason $finishReason = FinishReason::Stop,
        public Usage $usage = new Usage(),
        public string $id = '',
        public string $model = '',
        public ?string $raw = null,
    ) {}

    /** text returns the response's concatenated text. */
    public function text(): string
    {
        return $this->message->text();
    }

    /**
     * toolCalls returns the tool calls the model requested.
     *
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        return $this->message->toolCalls();
    }

    /** withRaw returns a copy carrying the provider's response body. */
    public function withRaw(?string $raw): self
    {
        return new self($this->message, $this->finishReason, $this->usage, $this->id, $this->model, $raw);
    }

    /** withUsage returns a copy carrying $usage. */
    public function withUsage(Usage $usage): self
    {
        return new self($this->message, $this->finishReason, $usage, $this->id, $this->model, $this->raw);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'model' => $this->model,
            'message' => $this->message,
            'finish_reason' => $this->finishReason->value,
            'usage' => $this->usage,
        ];
    }
}
