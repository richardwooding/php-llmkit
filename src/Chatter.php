<?php

declare(strict_types=1);

namespace LlmKit;

/** Chatter produces a single assistant response for a conversation. */
interface Chatter extends Client
{
    public function chat(Request $request): Response;
}
