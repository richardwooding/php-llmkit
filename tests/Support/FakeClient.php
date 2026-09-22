<?php

declare(strict_types=1);

namespace LlmKit\Tests\Support;

use LlmKit\Chatter;
use LlmKit\FinishReason;
use LlmKit\Message;
use LlmKit\Request;
use LlmKit\Response;
use LlmKit\Usage;
use LogicException;

/** FakeClient answers with queued responses and records the requests. */
final class FakeClient implements Chatter
{
    /** @var list<Request> */
    public array $seen = [];

    /** @param list<Response> $responses */
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private array $responses = [],
    ) {}

    public static function text(string $text, string $model = 'fake-1'): self
    {
        return new self('fake', $model, [
            new Response(Message::assistantText($text), FinishReason::Stop, new Usage(1, 1, 2)),
        ]);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function chat(Request $request): Response
    {
        // Snapshot the conversation: Tools::run keeps appending to the same request.
        $this->seen[] = new Request($request->messages, $request->tools);
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new LogicException('FakeClient has no queued response');
        }

        return $response;
    }
}
