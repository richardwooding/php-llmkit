<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenAi;

use LlmKit\Chunk;
use LlmKit\ChunkKind;
use LlmKit\Exception\ApiException;
use LlmKit\Internal\Arr;
use LlmKit\Internal\Json;
use LlmKit\ReasoningDelta;
use LlmKit\ToolCallDelta;

/**
 * OpenAiStreamState turns Responses API SSE events into chunks.
 *
 * @internal
 */
final class OpenAiStreamState
{
    private const string EVENT_OUTPUT_ITEM_ADDED = 'response.output_item.added';
    private const string EVENT_OUTPUT_ITEM_DONE = 'response.output_item.done';
    private const string EVENT_OUTPUT_TEXT_DELTA = 'response.output_text.delta';
    private const string EVENT_REFUSAL_DELTA = 'response.refusal.delta';
    private const string EVENT_FUNCTION_ARGS_DELTA = 'response.function_call_arguments.delta';
    private const string EVENT_REASONING_SUMMARY = 'response.reasoning_summary_text.delta';
    private const string EVENT_REASONING_TEXT = 'response.reasoning_text.delta';
    private const string EVENT_COMPLETED = 'response.completed';
    private const string EVENT_INCOMPLETE = 'response.incomplete';
    private const string EVENT_FAILED = 'response.failed';
    private const string EVENT_ERROR = 'error';

    private bool $hasTools = false;
    private bool $done = false;

    /** apply decodes one SSE data frame, returning the chunk it produced. */
    public function apply(string $data): ?Chunk
    {
        $event = Json::decodeObject($data, 'decode stream event');
        $item = Arr::obj($event, 'item');
        $index = Arr::int($event, 'output_index');
        $delta = Arr::str($event, 'delta');

        switch (Arr::str($event, 'type')) {
            case self::EVENT_OUTPUT_ITEM_ADDED:
                if (Arr::str($item, 'type') !== OpenAiMapper::TYPE_FUNCTION_CALL) {
                    return null;
                }
                $this->hasTools = true;

                return Chunk::toolCall(new ToolCallDelta(
                    index: $index,
                    id: Arr::str($item, 'call_id'),
                    name: Arr::str($item, 'name'),
                ), $data);
            case self::EVENT_FUNCTION_ARGS_DELTA:
                return Chunk::toolCall(new ToolCallDelta(index: $index, arguments: $delta), $data);
            case self::EVENT_OUTPUT_TEXT_DELTA:
            case self::EVENT_REFUSAL_DELTA:
                return Chunk::text($delta, $data);
            case self::EVENT_REASONING_SUMMARY:
            case self::EVENT_REASONING_TEXT:
                return Chunk::reasoning(new ReasoningDelta(index: $index, text: $delta), $data);
            case self::EVENT_OUTPUT_ITEM_DONE:
                // The reasoning item's id and encrypted_content only exist on
                // the completed item and must be echoed back on the next turn.
                if (Arr::str($item, 'type') !== OpenAiMapper::TYPE_REASONING) {
                    return null;
                }

                return new Chunk(ChunkKind::Reasoning, raw: $data, reasoning: new ReasoningDelta(
                    index: $index,
                    signature: Arr::str($item, 'id'),
                    encrypted: Arr::str($item, 'encrypted_content'),
                ));
            case self::EVENT_COMPLETED:
            case self::EVENT_INCOMPLETE:
                $this->done = true;

                return $this->finish(Arr::obj($event, 'response'), $data);
            case self::EVENT_FAILED:
                throw OpenAiMapper::failure(Arr::obj($event, 'response'), $data);
            case self::EVENT_ERROR:
                throw ApiException::create(
                    provider: OpenAiClient::ID,
                    errorCode: Arr::str($event, 'code'),
                    detail: Arr::str($event, 'message'),
                    body: $data,
                );
            default:
                return null;
        }
    }

    /** isDone reports whether a terminal event has been seen. */
    public function isDone(): bool
    {
        return $this->done;
    }

    /** @param array<string,mixed> $response */
    private function finish(array $response, string $raw): Chunk
    {
        $usage = Arr::obj($response, 'usage');

        return Chunk::finish(
            OpenAiMapper::finishReason(
                $response,
                $this->hasTools || OpenAiMapper::hasFunctionCall($response),
            ),
            $usage === [] ? null : OpenAiMapper::usage($usage),
            $raw,
        );
    }
}
