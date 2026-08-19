<?php

namespace App\Actions\Cati;

use App\Enums\ConformanceVerdict;
use App\Enums\SubmissionSectionKey;
use App\Models\Submission;
use App\Models\SubmissionChat;
use App\Support\Cati\ConformanceChecks;
use App\Support\Cati\DeviationRules;
use App\Support\Cati\SubmissionRequirements;

/**
 * Gives a submission's interview a first line, before the person ever types
 * one.
 *
 * An empty chat is a blank box with a hint underneath it — nothing invites
 * the first message, and nothing shows that the assistant already knows
 * anything. This writes that first message from the same deterministic
 * pieces the checklist already computes (SubmissionRequirements,
 * DeviationRules), so it costs no model call and can never say something the
 * record doesn't support.
 *
 * Deliberately NOT a job: it's plain PHP over data already in memory, so
 * there is nothing to wait for and no "gerando…" state to show.
 */
class SeedSubmissionChatOpening
{
    /** @return array<string, int> severity => sort weight, lowest first */
    private const SEVERITY_ORDER = ['high' => 0, 'medium' => 1, 'low' => 2];

    public function handle(SubmissionChat $chat): void
    {
        // A chat with any message already has its own history — seeding here
        // would either duplicate the opening or, worse, land in the middle of
        // a real conversation.
        if ($chat->messages()->exists()) {
            return;
        }

        $chat->loadMissing('submission.solution');

        $chat->messages()->create([
            'role'    => 'assistant',
            'content' => $this->build($chat->submission),
        ]);
    }

    private function build(Submission $submission): string
    {
        $solution = $submission->solution;

        if ($solution === null) {
            return 'Oi! Essa submissão ainda não está ligada a uma solução do catálogo — se ela já existir lá, '
                . "é só linkar no cabeçalho que eu aproveito o que já sabemos sobre ela.\n\n"
                . $this->nextQuestion($submission);
        }

        $requirements = SubmissionRequirements::for($submission);
        $facts = collect($requirements['facts'])->take(2);

        $known = $facts->isEmpty()
            ? ''
            : 'Já sei pelo catálogo: ' . $facts->map(fn (array $f) => "{$f['label']} {$f['value']}")->implode(', ') . ". Não preciso perguntar isso.\n\n";

        return "Oi! Vamos preparar a submissão da {$solution->name} para o CATI.\n\n{$known}"
            . $this->nextQuestion($submission);
    }

    /**
     * The single highest-value thing to ask first.
     *
     * A genuine VIOLATION (the solution is actually off the target cloud,
     * checked against a real value on the record) jumps the queue — it is
     * worth flagging before anything else. An `Unknown` verdict does not: on
     * a submission with no solution at all, nearly every conformance check
     * reads as unknown, and letting those win would mean the very first
     * message asks about infrastructure before asking what the proposal even
     * is. So the order is: real violation, then the first unanswered
     * mandatory section (the natural "start broad" order), then whatever
     * completeness question is left, then nothing left to ask.
     */
    private function nextQuestion(Submission $submission): string
    {
        $violation = collect(ConformanceChecks::for($submission))
            ->first(fn (array $check) => $check['verdict'] === ConformanceVerdict::Violation);

        if ($violation !== null) {
            return $violation['question'];
        }

        $missing = SubmissionRequirements::missingMandatory($submission);

        if ($missing !== []) {
            return SubmissionSectionKey::from($missing[0])->question();
        }

        $topDeviation = collect(DeviationRules::for($submission))
            ->sortBy(fn (array $rule) => self::SEVERITY_ORDER[$rule['severity']] ?? 3)
            ->first();

        if ($topDeviation !== null) {
            return $topDeviation['question'];
        }

        return 'As seções obrigatórias já estão preenchidas — quer ajustar alguma antes de resumir para os slides?';
    }
}
