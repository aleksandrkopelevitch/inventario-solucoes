<?php

namespace App\Enums;

/**
 * The trigger a pipeline is started by — the `triggerSpec.type` of the stored
 * document, and the one key the generator produces nothing for.
 *
 * Distribution over the 201 exported pipelines: `http` 66, `rest` 46,
 * `scheduler` 37, `event` 31, `http-file` 3, and 18 with no trigger at all
 * (`triggerCategory: "None"` — a pipeline only ever called by another one).
 *
 * Two of these are worth knowing apart before writing any code against them:
 *
 * - **Only a web protocol gives the test runner something to call.** A
 *   scheduler fires on a cron and an event on a name; neither has a URL, so a
 *   matrix of HTTP cases against one is not a stricter test, it is a test of
 *   nothing.
 * - **`name` is not `type` for the scheduler.** Every other kind writes its
 *   own type there; a scheduler writes the canvas PRESET the author picked —
 *   `custom-scheduler` 25, `5min-scheduler` 7, `30min-scheduler` 5 — and the
 *   preset does not bind the cron (one `5min-scheduler` runs `0 0 23 ? * * *`).
 *   Synthesis always says `custom-scheduler`, which is the only one of the
 *   three that claims nothing about the schedule beside it.
 */
enum DigibeeTriggerKind: string
{
    case Rest = 'rest';
    case Http = 'http';
    case HttpFile = 'http-file';
    case Scheduler = 'scheduler';
    case Event = 'event';

    /** What goes in `triggerSpec.name` — the same as the type, except for the scheduler. */
    public function specName(): string
    {
        return match ($this) {
            self::Scheduler => 'custom-scheduler',
            default         => $this->value,
        };
    }

    /** Reachable over HTTP at a URL of its own, and therefore testable by the runner. */
    public function isWebProtocol(): bool
    {
        return in_array($this, [self::Rest, self::Http, self::HttpFile], true);
    }

    /** `triggerCategory`, as the stored document spells it. */
    public function category(): string
    {
        return match ($this) {
            self::Rest, self::Http, self::HttpFile => 'Web Protocols',
            self::Scheduler                        => 'Scheduling',
            self::Event                            => 'Messaging and Events',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Rest      => 'REST',
            self::Http      => 'HTTP',
            self::HttpFile  => 'HTTP File',
            self::Scheduler => 'Agendamento',
            self::Event     => 'Evento',
        };
    }
}
