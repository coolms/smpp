<?php

declare(strict_types=1);

namespace CoolMS\Smpp\Transport;

use Io\Poll\Context;
use Io\Poll\Event;
use StreamPollHandle;
use Time\Duration;

use function in_array;
use function max;

/**
 * Io\Poll (PHP 8.6; symfony/polyfill-io-poll before it, on stream_select()): one context per wait, one watcher. A
 * hang-up or an error counts as ready too, since the read or the write that follows is what tells what happened.
 */
final class IoPollWaiter implements Waiter
{
    public function readable(mixed $stream, float $seconds): bool
    {
        return $this->wait($stream, Event::Read, $seconds);
    }

    public function writable(mixed $stream, float $seconds): bool
    {
        return $this->wait($stream, Event::Write, $seconds);
    }

    /** @param resource $stream */
    private function wait(mixed $stream, Event $event, float $seconds): bool
    {
        $context = new Context();
        $context->add(new StreamPollHandle($stream), [$event]);
        foreach ($context->wait(Duration::fromNanoseconds((int) max(0, $seconds * 1e9))) as $watcher) {
            $events = $watcher->getTriggeredEvents();
            if (in_array($event, $events, true) || in_array(Event::HangUp, $events, true) || in_array(Event::Error, $events, true)) {
                return true;
            }
        }

        return false;
    }
}
