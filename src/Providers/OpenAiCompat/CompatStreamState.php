<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenAiCompat;

use LlmKit\Chunk;
use LlmKit\ChunkKind;
use LlmKit\Internal\Arr;
use LlmKit\Internal\Json;
use LlmKit\ToolCallDelta;
use LlmKit\Usage;

/**
 * CompatStreamState turns Chat Completions SSE frames into chunks.
 *
 * @internal
 */
final class CompatStreamState
{
    private string $finishReason = '';
    private ?Usage $usage = null;
    private bool $hasTools = false;

    public function __construct(private readonly CompatMapper $mapper) {}

    /**
     * apply decodes one SSE data frame and returns the chunks it produced.
     *
     * @return list<Chunk>
     */
    public function apply(string $data): array
    {
        $frame = Json::decodeObject($data, 'decode stream chunk');
        $usage = $this->mapper->usageOf($frame);
        if ($usage !== null) {
            $this->usage = $usage;
        }
        $choices = Arr::objects($frame, 'choices');
        if ($choices === []) {
            return [];
        }
        $choice = $choices[0];
        $reason = Arr::str($choice, 'finish_reason');
        if ($reason !== '') {
            $this->finishReason = $reason;
        }
        if (!is_array($choice['delta'] ?? null)) {
            return [];
        }
        /** @var array<string,mixed> $delta */
        $delta = $choice['delta'];
        $chunks = [];
        $reasoning = $this->mapper->reasoningText($delta);
        if ($reasoning !== '') {
            $chunks[] = new Chunk(ChunkKind::Reasoning, text: $reasoning, raw: $data);
        }
        $content = $delta['content'] ?? null;
        if (is_string($content) && $content !== '') {
            $chunks[] = Chunk::text($content, $data);
        }
        foreach (Arr::objects($delta, 'tool_calls') as $i => $call) {
            $this->hasTools = true;
            $function = Arr::obj($call, 'function');
            $chunks[] = Chunk::toolCall(new ToolCallDelta(
                index: Arr::int($call, 'index', $i),
                id: Arr::str($call, 'id'),
                name: Arr::str($function, 'name'),
                arguments: Arr::str($function, 'arguments'),
            ), $data);
        }

        return $chunks;
    }

    /** finish builds the terminal chunk of the stream. */
    public function finish(): Chunk
    {
        return Chunk::finish(
            CompatMapper::finishReason($this->finishReason, $this->hasTools),
            $this->usage,
        );
    }
}
