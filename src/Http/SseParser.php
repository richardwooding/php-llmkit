<?php

declare(strict_types=1);

namespace LlmKit\Http;

/**
 * SseParser turns arbitrary byte chunks into server-sent events. Lines have
 * no length limit (OpenAI's response.completed frames exceed 64 KB), "data:"
 * lines accumulate, ":" comments are dropped and a "[DONE]" payload ends the
 * stream.
 *
 * @internal
 */
final class SseParser
{
    private const string DONE = '[DONE]';

    private string $buffer = '';
    private string $name = '';
    private string $id = '';
    /** @var list<string> */
    private array $data = [];
    private bool $done = false;

    /**
     * feed consumes a byte chunk and returns the events it completed.
     *
     * @return list<Event>
     */
    public function feed(string $chunk): array
    {
        if ($this->done) {
            return [];
        }
        $this->buffer .= $chunk;
        $events = [];
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $pos), "\r");
            $this->buffer = substr($this->buffer, $pos + 1);
            $event = $this->line($line);
            if ($this->done) {
                return $events;
            }
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * finish flushes the trailing partial line and any buffered event.
     *
     * @return list<Event>
     */
    public function finish(): array
    {
        if ($this->done) {
            return [];
        }
        $events = [];
        if ($this->buffer !== '') {
            $line = rtrim($this->buffer, "\r\n");
            $this->buffer = '';
            $event = $this->line($line);
            if ($this->done) {
                return $events;
            }
            if ($event !== null) {
                $events[] = $event;
            }
        }
        $event = $this->flush();
        if ($event !== null) {
            $events[] = $event;
        }

        return $events;
    }

    /** isDone reports whether a [DONE] sentinel ended the stream. */
    public function isDone(): bool
    {
        return $this->done;
    }

    /** @phpstan-impure */
    private function line(string $line): ?Event
    {
        if ($line === '') {
            return $this->flush();
        }
        $pos = strpos($line, ':');
        [$field, $value] = $pos === false ? [$line, ''] : [substr($line, 0, $pos), substr($line, $pos + 1)];
        if (str_starts_with($value, ' ')) {
            $value = substr($value, 1);
        }
        match ($field) {
            'event' => $this->name = $value,
            'data' => $this->data[] = $value,
            'id' => $this->id = $value,
            default => null,
        };

        return null;
    }

    /** @phpstan-impure */
    private function flush(): ?Event
    {
        if ($this->data === [] && $this->name === '' && $this->id === '') {
            return null;
        }
        $event = new Event($this->name, implode("\n", $this->data), $this->id);
        $this->name = '';
        $this->id = '';
        $this->data = [];
        if ($event->data === self::DONE) {
            $this->done = true;

            return null;
        }

        return $event;
    }
}
