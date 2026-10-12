<?php

namespace App\Support\Documentation;

/**
 * A "linha do tempo" — the fifth SCENE type: dated events in order (a
 * project's milestones, a system's history, a roadmap), drawn along an axis
 * that grows from the past into the future.
 *
 * It is not the "fluxo em etapas". A process repeats and has no date; a
 * timeline happens ONCE, and every event carries `when` — written the way the
 * page writes it ("Jan/2026", "Sprint 3", "Q2", "15/03") rather than parsed,
 * since the corpus dates things in a dozen formats and the figure only needs
 * the order, which the list already gives. A page with no dates is a process,
 * and the prompt sends the model to say so.
 *
 * `status` is content, like a comparison's mark: `done` (concluído),
 * `current` (agora — at most ONE: it is where the reader stands), `planned`
 * (previsto) or none; an unknown one is a validation error. The axis is drawn
 * solid through the past and dashed into the future. One event may be the
 * `highlight` — the "Marco".
 */
final class TimelineScene
{
    public const TYPE = 'timeline';

    public const MIN_EVENTS = 2;

    public const MAX_EVENTS = 8;

    public const MAX_WHEN = 20;

    public const MAX_TITLE = 40;

    public const MAX_DETAIL = 100;

    public const MAX_CAPTION = 120;

    /** The statuses an event may carry; '' is "not said". */
    public const STATUSES = ['done', 'current', 'planned'];

    /**
     * @param  list<array{when: string, title: string, detail: string, status: string, highlight: bool, icon: string}>  $events
     */
    private function __construct(
        public readonly string $caption,
        public readonly array $events,
    ) {}

    /**
     * @param  array<mixed>  $payload
     * @return list<string> empty when the payload is a valid timeline
     */
    public static function validate(array $payload): array
    {
        $problems = [];

        $caption = $payload['caption'] ?? '';

        if (! is_string($caption)) {
            $problems[] = 'O campo "caption" deve ser um texto.';
        } elseif (mb_strlen(trim($caption)) > self::MAX_CAPTION) {
            $problems[] = 'O campo "caption" passa de ' . self::MAX_CAPTION . ' caracteres.';
        }

        $events = $payload['events'] ?? null;

        if (! is_array($events) || ! array_is_list($events)) {
            return [...$problems, 'O campo "events" é obrigatório e deve ser uma lista.'];
        }

        if (count($events) < self::MIN_EVENTS || count($events) > self::MAX_EVENTS) {
            $problems[] = 'A lista "events" deve ter entre ' . self::MIN_EVENTS . ' e ' . self::MAX_EVENTS . ' eventos (tem ' . count($events) . ').';
        }

        $current = 0;
        $highlights = 0;

        foreach ($events as $i => $event) {
            $n = $i + 1;

            if (! is_array($event)) {
                $problems[] = "O evento {$n} deve ser um objeto.";

                continue;
            }

            $when = $event['when'] ?? null;

            if (! is_string($when) || trim($when) === '') {
                $problems[] = "O evento {$n} precisa de \"when\" — a data ou o período, como a página escreve.";
            } elseif (mb_strlen(trim($when)) > self::MAX_WHEN) {
                $problems[] = "O \"when\" do evento {$n} passa de " . self::MAX_WHEN . ' caracteres — use só a data ou o período.';
            }

            $title = $event['title'] ?? null;

            if (! is_string($title) || trim($title) === '') {
                $problems[] = "O evento {$n} precisa de um \"title\".";
            } elseif (mb_strlen(trim($title)) > self::MAX_TITLE) {
                $problems[] = "O \"title\" do evento {$n} passa de " . self::MAX_TITLE . ' caracteres — encurte.';
            }

            $detail = $event['detail'] ?? '';

            if (! is_string($detail) || mb_strlen(trim($detail)) > self::MAX_DETAIL) {
                $problems[] = "O \"detail\" do evento {$n} deve ser um texto de até " . self::MAX_DETAIL . ' caracteres.';
            }

            $status = $event['status'] ?? '';

            if (! in_array($status, ['', ...self::STATUSES], true)) {
                $problems[] = "O \"status\" do evento {$n} deve ser \"done\", \"current\", \"planned\" ou vazio.";
            } elseif ($status === 'current') {
                $current++;
            }

            if (($event['highlight'] ?? false) === true) {
                $highlights++;
            }
        }

        if ($current > 1) {
            $problems[] = 'No máximo UM evento pode ter "status": "current" — é onde se está agora (há ' . $current . ').';
        }

        if ($highlights > 1) {
            $problems[] = 'No máximo UM evento pode ter "highlight": true (há ' . $highlights . ').';
        }

        return $problems;
    }

    /** @param  array<mixed>  $payload  a payload `validate()` accepted */
    public static function fromArray(array $payload): self
    {
        return new self(
            trim((string) ($payload['caption'] ?? '')),
            array_map(fn (array $event): array => [
                'when'      => trim((string) $event['when']),
                'title'     => trim((string) $event['title']),
                'detail'    => trim((string) ($event['detail'] ?? '')),
                'status'    => (string) ($event['status'] ?? ''),
                'highlight' => ($event['highlight'] ?? false) === true,
                'icon'      => SceneIcons::sanitize($event['icon'] ?? null),
            ], $payload['events']),
        );
    }

    /** @return array{type: string, caption: string, events: list<array{when: string, title: string, detail: string, status: string, highlight: bool, icon: string}>} */
    public function toArray(): array
    {
        return ['type' => self::TYPE, 'caption' => $this->caption, 'events' => $this->events];
    }
}
