<?php

declare(strict_types=1);

namespace LlmKit\Providers\Anthropic;

use LlmKit\Chunk;
use LlmKit\ChunkKind;
use LlmKit\Exception\ApiException;
use LlmKit\Internal\Arr;
use LlmKit\Internal\Json;
use LlmKit\ReasoningDelta;
use LlmKit\ToolCallDelta;

/**
 * AnthropicStreamState turns Messages API SSE events into chunks.
 *
 * @internal
 */
final class AnthropicStreamState
{
    private const string EVENT_MESSAGE_START = 'message_start';
    private const string EVENT_CONTENT_BLOCK_START = 'content_block_start';
    private const string EVENT_CONTENT_BLOCK_DELTA = 'content_block_delta';
    private const string EVENT_MESSAGE_DELTA = 'message_delta';
    private const string EVENT_MESSAGE_STOP = 'message_stop';
    private const string EVENT_ERROR = 'error';

    private const string DELTA_TEXT = 'text_delta';
    private const string DELTA_THINKING = 'thinking_delta';
    private const string DELTA_INPUT_JSON = 'input_json_delta';
    private const string DELTA_SIGNATURE = 'signature_delta';

    /**
     * Content block index to the ordinal of its tool call or reasoning block,
     * which is what Stream::collect keys reassembly on.
     *
     * @var array<int,int>
     */
    private array $tools = [];

    /** @var array<int,int> */
    private array $reasons = [];

    private string $stopReason = '';

    /** @var array<string,mixed> */
    private array $usage = [];

    private bool $done = false;

    /**
     * apply decodes one SSE data frame and returns the chunks it produced.
     *
     * @return list<Chunk>
     */
    public function apply(string $data): array
    {
        $event = Json::decodeObject($data, 'decode stream event');

        switch (Arr::str($event, 'type')) {
            case self::EVENT_MESSAGE_START:
                $this->usage = Arr::obj(Arr::obj($event, 'message'), 'usage');

                return [];
            case self::EVENT_CONTENT_BLOCK_START:
                return $this->blockStart($event, $data);
            case self::EVENT_CONTENT_BLOCK_DELTA:
                return $this->blockDelta($event, $data);
            case self::EVENT_MESSAGE_DELTA:
                $this->messageDelta($event);

                return [];
            case self::EVENT_MESSAGE_STOP:
                $this->done = true;

                return [$this->finish()];
            case self::EVENT_ERROR:
                // The API can emit this after a 200 has already been sent, so
                // there is no HTTP status to report.
                $error = Arr::obj($event, 'error');
                $type = Arr::str($error, 'type');

                throw ApiException::create(
                    provider: AnthropicClient::ID,
                    errorCode: $type,
                    type: $type,
                    detail: Arr::str($error, 'message', 'stream error'),
                    body: $data,
                );
            default:
                return [];
        }
    }

    /** isDone reports whether message_stop has been seen. */
    public function isDone(): bool
    {
        return $this->done;
    }

    /** finish builds the terminal chunk of the stream. */
    public function finish(): Chunk
    {
        return Chunk::finish(
            AnthropicMapper::finishReason($this->stopReason),
            AnthropicMapper::usage($this->usage),
        );
    }

    /**
     * @param array<string,mixed> $event
     *
     * @return list<Chunk>
     */
    private function blockStart(array $event, string $raw): array
    {
        $block = Arr::obj($event, 'content_block');
        $index = Arr::int($event, 'index');

        switch (Arr::str($block, 'type')) {
            case AnthropicMapper::BLOCK_TOOL_USE:
                $ordinal = count($this->tools);
                $this->tools[$index] = $ordinal;

                return [Chunk::toolCall(new ToolCallDelta(
                    index: $ordinal,
                    id: Arr::str($block, 'id'),
                    name: Arr::str($block, 'name'),
                ), $raw)];
            case AnthropicMapper::BLOCK_THINKING:
                $this->reasons[$index] = count($this->reasons);

                return [];
            case AnthropicMapper::BLOCK_REDACTED_THINKING:
                // Redacted blocks arrive whole: the opaque data is the only payload.
                $ordinal = count($this->reasons);
                $this->reasons[$index] = $ordinal;

                return [new Chunk(ChunkKind::Reasoning, raw: $raw, reasoning: new ReasoningDelta(
                    index: $ordinal,
                    encrypted: Arr::str($block, 'data'),
                ))];
            default:
                return [];
        }
    }

    /**
     * @param array<string,mixed> $event
     *
     * @return list<Chunk>
     */
    private function blockDelta(array $event, string $raw): array
    {
        $delta = Arr::obj($event, 'delta');
        $index = Arr::int($event, 'index');

        switch (Arr::str($delta, 'type')) {
            case self::DELTA_TEXT:
                return [Chunk::text(Arr::str($delta, 'text'), $raw)];
            case self::DELTA_THINKING:
                return [Chunk::reasoning(new ReasoningDelta(
                    index: $this->reasons[$index] ?? 0,
                    text: Arr::str($delta, 'thinking'),
                ), $raw)];
            case self::DELTA_SIGNATURE:
                return [new Chunk(ChunkKind::Reasoning, raw: $raw, reasoning: new ReasoningDelta(
                    index: $this->reasons[$index] ?? 0,
                    signature: Arr::str($delta, 'signature'),
                ))];
            case self::DELTA_INPUT_JSON:
                if (!isset($this->tools[$index])) {
                    return [];
                }

                return [Chunk::toolCall(new ToolCallDelta(
                    index: $this->tools[$index],
                    arguments: Arr::str($delta, 'partial_json'),
                ), $raw)];
            default:
                return [];
        }
    }

    /** @param array<string,mixed> $event */
    private function messageDelta(array $event): void
    {
        $reason = Arr::str(Arr::obj($event, 'delta'), 'stop_reason');
        if ($reason !== '') {
            $this->stopReason = $reason;
        }
        // message_delta usage repeats only what changed, so merge field-wise.
        foreach (Arr::obj($event, 'usage') as $key => $value) {
            if (is_int($value) && $value > 0) {
                $this->usage[$key] = $value;
            }
        }
    }
}
