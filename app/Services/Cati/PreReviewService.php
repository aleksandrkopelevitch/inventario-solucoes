<?php

namespace App\Services\Cati;

use App\Models\Submission;
use App\Support\Cati\ConformanceChecks;
use App\Support\Cati\DeviationRules;
use App\Support\Cati\SubmissionRequirements;
use Laravel\Ai\Responses\AgentResponse;

use function Laravel\Ai\agent;

/**
 * Runs the committee against a submission before the committee does.
 *
 * The cheapest high-value use of a model in this whole module: a few seconds
 * of adversarial reading, against a submission that took days to prepare,
 * catching the objection that would otherwise cost a two-week round trip.
 *
 * Two things keep it honest:
 *
 * - **It is handed everything deterministic first.** The conformance verdicts
 *   and the derived questions go into the prompt, and the model is told not to
 *   repeat them — its job is what the RULES CANNOT SEE: an argument that does
 *   not follow, a cost with no basis, a risk the text implies and never names.
 *   Otherwise it spends its output restating the checklist.
 * - **It never rewrites anything.** The result is advice attached to the
 *   submission, not a draft applied to it. A reviewer decides what to do.
 */
class PreReviewService
{
    /** One finding per block: severity, the section it lands on, and the objection. */
    private const FINDING_PATTERN = '/^`{4}achado:(alta|media|baixa):([a-z_]+)[ \t]*\R(.*?)\R`{4}[ \t]*$/ms';

    public function __construct(private readonly PreReviewPromptBuilder $prompts) {}

    /**
     * @return array{findings: list<array{severity: string, section: string, text: string}>, meta: array<string, mixed>}
     */
    public function handle(Submission $submission): array
    {
        $submission->loadMissing(['sections', 'solution.diagrams', 'sources']);

        $response = $this->prompt($this->prompts->userPrompt(
            submission: $submission,
            requirements: SubmissionRequirements::for($submission),
            conformance: ConformanceChecks::for($submission),
            deviations: DeviationRules::for($submission),
        ));

        $findings = $this->extractFindings($response->text);

        return [
            'findings' => $findings,
            'meta'     => [
                'provider'  => config('services.cati.provider'),
                'model'     => config('services.cati.model'),
                'tokens'    => ['prompt' => $response->usage->promptTokens, 'completion' => $response->usage->completionTokens],
                'ran_at'    => now()->toIso8601String(),
                'discarded' => max(0, substr_count($response->text, '````achado:') - count($findings)),
            ],
        ];
    }

    /**
     * @return list<array{severity: string, section: string, text: string}>
     */
    private function extractFindings(string $text): array
    {
        preg_match_all(self::FINDING_PATTERN, trim($text), $matches, PREG_SET_ORDER);

        $findings = [];

        foreach ($matches as $match) {
            $text = trim($match[3]);

            if ($text === '') {
                continue;
            }

            $findings[] = [
                'severity' => $match[1],
                'section'  => $match[2],
                'text'     => $text,
            ];
        }

        // Highest severity first: a pre-review nobody reads to the end should
        // still have delivered its worst news.
        usort($findings, fn (array $a, array $b) => ['alta' => 0, 'media' => 1, 'baixa' => 2][$a['severity']]
            <=> ['alta' => 0, 'media' => 1, 'baixa' => 2][$b['severity']]);

        return $findings;
    }

    /** Protected so tests can substitute the real API call with a test double. */
    protected function prompt(string $prompt): AgentResponse
    {
        return agent(instructions: $this->prompts->systemPrompt())->prompt(
            $prompt,
            provider: config('services.cati.provider'),
            model: config('services.cati.model'),
            timeout: (int) config('services.cati.timeout'),
        );
    }
}
