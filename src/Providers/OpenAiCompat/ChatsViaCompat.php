<?php

declare(strict_types=1);

namespace LlmKit\Providers\OpenAiCompat;

use LlmKit\Request;
use LlmKit\Response;

/**
 * ChatsViaCompat implements Chatter over a CompatClient.
 *
 * @internal
 */
trait ChatsViaCompat
{
    public function chat(Request $request): Response
    {
        return $this->inner->chat($request);
    }
}
