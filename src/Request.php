<?php

declare(strict_types=1);

namespace LlmKit;

/**
 * Request describes a chat completion. Null distinguishes "unset" from a
 * meaningful zero. The object is mutable: Tools::run appends the assistant
 * and tool turns it generates to $messages, leaving the caller with the whole
 * conversation.
 */
final class Request
{
    /**
     * @param list<Message>                        $messages
     * @param list<Tool>                           $tools
     * @param list<string>                         $stop
     * @param array<string,mixed>                  $extra           merged into the top level of the wire body for any provider
     * @param array<string,array<string,mixed>>    $providerOptions keyed by provider ID, merged after $extra
     */
    public function __construct(
        public array $messages = [],
        public array $tools = [],
        public ?ToolChoice $toolChoice = null,
        public int $maxTokens = 0,
        public ?float $temperature = null,
        public ?float $topP = null,
        public array $stop = [],
        public ?int $seed = null,
        public ?ResponseFormat $format = null,
        public ?ReasoningConfig $reasoning = null,
        public ?CacheConfig $cache = null,
        public array $extra = [],
        public array $providerOptions = [],
    ) {}

    /** prompt builds a request with one user turn and an optional system turn. */
    public static function prompt(string $prompt, string $system = ''): self
    {
        $messages = $system === ''
            ? [Message::userText($prompt)]
            : [Message::system($system), Message::userText($prompt)];

        return new self($messages);
    }

    /** append adds messages to the conversation. */
    public function append(Message ...$messages): void
    {
        foreach ($messages as $message) {
            $this->messages[] = $message;
        }
    }

    /**
     * providerExtra returns the merged provider-specific overrides for $id.
     *
     * @return array<string,mixed>
     */
    public function providerExtra(string $id): array
    {
        return [...$this->extra, ...($this->providerOptions[$id] ?? [])];
    }

}
