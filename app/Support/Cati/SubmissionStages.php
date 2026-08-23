<?php

namespace App\Support\Cati;

use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use App\Models\Submission;

/**
 * The four stages a submission goes through, derived from the record.
 *
 * This is what makes the workbench read as a wizard without behaving like
 * one. A real stepper — one screen per step, Voltar/Avançar — would be a lie
 * here: preparing a CATI submission is genuinely back-and-forth (a question
 * about costs sends you back to the material, confirming a section reopens
 * the one before it). So the stages are *reported*, never *enforced*: nothing
 * is locked, nothing has to be clicked to advance, and a stage flips to done
 * because the underlying condition became true.
 *
 * Deterministic and free — no model call, no stored column that could drift
 * from what the record actually says. There is deliberately no "skipped"
 * state and no way to tick a stage by hand: a checkbox that says the material
 * is gathered when it isn't is worse than an honest empty circle. A stage that
 * really was skipped simply stays unfinished while the pointer moves past it
 * (see the note on `$current` below).
 */
class SubmissionStages
{
    public const DONE = 'done';

    public const CURRENT = 'current';

    public const PENDING = 'pending';

    /**
     * @return list<array{key: string, label: string, hint: string, state: string}>
     */
    public static function for(Submission $submission): array
    {
        $submission->loadMissing(['sections', 'sources']);

        $mandatory = SubmissionSectionKey::mandatoryCases();
        $sections = $submission->sections->keyBy(fn ($section) => $section->key->value);

        $answered = collect($mandatory)->every(
            fn (SubmissionSectionKey $key) => filled($sections->get($key->value)?->content),
        );

        $confirmed = collect($mandatory)->every(
            fn (SubmissionSectionKey $key) => $sections->get($key->value)?->state === SubmissionSectionState::Confirmed,
        );

        $stages = [
            ['key' => 'material', 'label' => 'Material', 'hint' => 'Decks, PDFs e textos que já existem', 'done' => $submission->sources->isNotEmpty()],
            ['key' => 'interview', 'label' => 'Entrevista', 'hint' => 'O assistente pergunta e redige', 'done' => $answered],
            ['key' => 'review', 'label' => 'Revisão', 'hint' => 'Você confirma cada seção', 'done' => $confirmed],
            ['key' => 'committee', 'label' => 'Comitê', 'hint' => 'Chamado, deck e deliberação', 'done' => $submission->status->isDecided()],
        ];

        // "Current" is the first unfinished stage AFTER the furthest one
        // already finished — not simply the first unfinished one.
        //
        // Both halves of that are load-bearing:
        //
        // - Looking forward from the furthest progress is what lets an
        //   optional stage be skipped. Attaching material is genuinely
        //   optional (a person can just answer questions), so "first
        //   unfinished" would pin the pointer to `material` forever and the
        //   strip would still say "Material" on a submission whose document is
        //   written. The skipped stage stays visibly unfinished — the pointer
        //   moves on, the claim doesn't.
        // - Stopping at the first unfinished one *after* that point is what
        //   keeps a vacuously-satisfied later stage from winning. A submission
        //   with material attached and every section empty is at the
        //   interview, not at the review — sending someone to confirm text
        //   that doesn't exist is worse than saying nothing.
        $lastDone = -1;

        foreach ($stages as $index => $stage) {
            if ($stage['done']) {
                $lastDone = $index;
            }
        }

        $current = null;

        foreach ($stages as $index => $stage) {
            if ($index > $lastDone && ! $stage['done']) {
                $current = $index;

                break;
            }
        }

        return array_map(fn (array $stage, int $index) => [
            'key'   => $stage['key'],
            'label' => $stage['label'],
            'hint'  => $stage['hint'],
            'state' => match (true) {
                $stage['done']      => self::DONE,
                $index === $current => self::CURRENT,
                default             => self::PENDING,
            },
        ], $stages, array_keys($stages));
    }

    /**
     * How much of the document exists, counted over ALL eleven sections — not
     * just the mandatory six.
     *
     * The deck asks for the other five, so a progress bar that reads 6/6 while
     * five slides are blank is the kind of "done" that only shows up in the
     * meeting.
     *
     * @return array{total: int, answered: int, confirmed: int, percent: int}
     */
    public static function progress(Submission $submission): array
    {
        $submission->loadMissing('sections');

        $sections = $submission->sections->keyBy(fn ($section) => $section->key->value);
        $all = SubmissionSectionKey::cases();

        $answered = collect($all)->filter(
            fn (SubmissionSectionKey $key) => filled($sections->get($key->value)?->content),
        )->count();

        $confirmed = collect($all)->filter(
            fn (SubmissionSectionKey $key) => $sections->get($key->value)?->state === SubmissionSectionState::Confirmed,
        )->count();

        $total = count($all);

        return [
            'total'     => $total,
            'answered'  => $answered,
            'confirmed' => $confirmed,
            'percent'   => $total === 0 ? 0 : (int) round($answered / $total * 100),
        ];
    }
}
