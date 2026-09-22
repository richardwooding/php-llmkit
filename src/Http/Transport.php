<?php

declare(strict_types=1);

namespace LlmKit\Http;

/**
 * Transport sends provider requests. Implementations do not interpret status
 * codes: they report what came back and let the caller decide, so error
 * envelopes are decoded in one place.
 */
interface Transport
{
    /** send performs a request and buffers the whole reply. */
    public function send(HttpRequest $request): HttpResponse;

    /**
     * openStream performs a request and returns once the status and headers
     * are known, leaving the body to be read incrementally.
     */
    public function openStream(HttpRequest $request): StreamedResponse;
}
