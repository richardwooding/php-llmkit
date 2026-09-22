<?php

declare(strict_types=1);

namespace LlmKit;

use Generator;

/** Stream turns a chunk sequence back into whole values. */
final class Stream
{
    /**
     * collect drains a stream into a Response, concatenating text,
     * reassembling reasoning blocks and tool-call arguments by index, and
     * keeping reasoning signatures so the result can be echoed back on the
     * next turn.
     *
     * @param iterable<int,Chunk> $chunks
     */
    public static function collect(iterable $chunks): Response
    {
        $text = '';
        /** @var array<int,array{text:string,signature:string,encrypted:string}> $reasoning */
        $reasoning = [];
        /** @var array<int,array{id:string,name:string,arguments:string}> $calls */
        $calls = [];
        $finish = null;
        $usage = new Usage();

        foreach ($chunks as $chunk) {
            switch ($chunk->kind) {
                case ChunkKind::Text:
                    $text .= $chunk->text;
                    break;
                case ChunkKind::Reasoning:
                    // Chunks without a delta are text-only fragments and land in block 0.
                    $delta = $chunk->reasoning ?? new ReasoningDelta(text: $chunk->text);
                    $block = $reasoning[$delta->index] ?? ['text' => '', 'signature' => '', 'encrypted' => ''];
                    $block['text'] .= $delta->text !== '' ? $delta->text : $chunk->text;
                    if ($delta->signature !== '') {
                        $block['signature'] = $delta->signature;
                    }
                    if ($delta->encrypted !== '') {
                        $block['encrypted'] = $delta->encrypted;
                    }
                    $reasoning[$delta->index] = $block;
                    break;
                case ChunkKind::ToolCall:
                    if ($chunk->toolCall === null) {
                        break;
                    }
                    $delta = $chunk->toolCall;
                    $call = $calls[$delta->index] ?? ['id' => '', 'name' => '', 'arguments' => ''];
                    if ($delta->id !== '') {
                        $call['id'] = $delta->id;
                    }
                    if ($delta->name !== '') {
                        $call['name'] = $delta->name;
                    }
                    $call['arguments'] .= $delta->arguments;
                    $calls[$delta->index] = $call;
                    break;
                case ChunkKind::Finish:
                    $finish = $chunk->finishReason ?? $finish;
                    if ($chunk->usage !== null) {
                        $usage = $chunk->usage;
                    }
                    break;
            }
        }

        $parts = [];
        ksort($reasoning);
        foreach ($reasoning as $block) {
            $part = new ReasoningPart($block['text'], $block['signature'], $block['encrypted']);
            if (!$part->isEmpty()) {
                $parts[] = $part;
            }
        }
        if ($text !== '') {
            $parts[] = new TextPart($text);
        }
        ksort($calls);
        foreach ($calls as $call) {
            $parts[] = new ToolCall(
                $call['id'],
                $call['name'],
                $call['arguments'] === '' ? '{}' : $call['arguments'],
            );
        }
        $finish ??= $calls === [] ? FinishReason::Stop : FinishReason::ToolCalls;

        return new Response(new Message(Role::Assistant, $parts), $finish, $usage);
    }

    /**
     * text yields only the text fragments of a stream, for printing.
     *
     * @param iterable<int,Chunk> $chunks
     *
     * @return Generator<int,string,mixed,void>
     */
    public static function text(iterable $chunks): Generator
    {
        foreach ($chunks as $chunk) {
            if ($chunk->kind === ChunkKind::Text && $chunk->text !== '') {
                yield $chunk->text;
            }
        }
    }
}
