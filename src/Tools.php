<?php

declare(strict_types=1);

namespace LlmKit;

use LlmKit\Exception\ToolLoopExceededException;
use Throwable;

/** Tools runs the model's tool calls and feeds the results back. */
final class Tools
{
    /**
     * run chats until the model stops requesting tools or $maxIterations
     * calls have been made, appending assistant and tool messages to
     * $request so the caller keeps the whole conversation. Tool failures and
     * unknown tool names are fed back to the model as error results. Usage
     * across iterations is summed.
     *
     * @param array<string,callable(array<string,mixed>,ToolCall):string> $tools keyed by tool name
     */
    public static function run(Chatter $chatter, Request $request, array $tools, int $maxIterations = 10): Response
    {
        if ($maxIterations <= 0) {
            $maxIterations = 10;
        }
        $total = new Usage();
        for ($i = 0; $i < $maxIterations; ++$i) {
            $response = $chatter->chat($request);
            $total = $total->add($response->usage);
            $calls = $response->toolCalls();
            if ($calls === []) {
                return $response->withUsage($total);
            }
            $request->append($response->message);
            $results = [];
            foreach ($calls as $call) {
                $results[] = self::invoke($tools, $call);
            }
            $request->append(Message::tool(...$results));
        }

        throw new ToolLoopExceededException(
            'llmkit: tool loop exceeded ' . $maxIterations . ' iterations',
        );
    }

    /** @param array<string,callable(array<string,mixed>,ToolCall):string> $tools */
    private static function invoke(array $tools, ToolCall $call): ToolResult
    {
        $fn = $tools[$call->name] ?? null;
        if ($fn === null) {
            return ToolResult::error($call->id, $call->name, sprintf('unknown tool "%s"', $call->name));
        }

        try {
            return ToolResult::text($call->id, $call->name, $fn($call->argumentsArray(), $call));
        } catch (Throwable $e) {
            return ToolResult::error($call->id, $call->name, $e->getMessage());
        }
    }
}
