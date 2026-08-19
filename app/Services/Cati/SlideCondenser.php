<?php

namespace App\Services\Cati;

use App\Enums\SubmissionSectionKey;
use App\Models\Submission;
use App\Models\SubmissionSection;
use App\Support\Cati\MarkdownToBlocks;
use App\Support\Cati\SlideTextValidator;
use Laravel\Ai\Responses\AgentResponse;

use function Laravel\Ai\agent;

/**
 * Condenses a submission's written sections into slide text.
 *
 * The one place in this module with a correction loop, and it earns it: unlike
 * the interview — where a reply is prose and a human reads it — this output
 * goes straight onto a slide nobody re-reads before the meeting. So what comes
 * back is measured (SlideTextValidator), and a section that doesn't fit is
 * asked again, naming what was wrong. Same shape as the flowSpec generator's
 * normalize/validate loop.
 *
 * A section that still doesn't fit after the last attempt is simply left
 * uncondensed — the deck then prints the full text, which is verbose but true.
 * Shipping a mangled summary would be worse.
 */
class SlideCondenser
{
    /** Matches one 4-backtick block tagged with its section key. */
    private const BLOCK_PATTERN = '/^`{4}slide:([a-z_]+)[ \t]*\R(.*?)\R`{4}[ \t]*$/ms';

    public function __construct(
        private readonly SlideCondenserPromptBuilder $prompts,
        private readonly MarkdownToBlocks $markdown,
        private readonly SlideTextValidator $validator,
    ) {}

    /**
     * Writes `slide_content` on every section that has prose, and returns what
     * happened per section for the audit trail.
     *
     * @return array{condensed: list<string>, failed: array<string, list<string>>, attempts: int}
     */
    public function handle(Submission $submission): array
    {
        $submission->loadMissing(['sections', 'solution']);

        // Sorted into the enum's own order rather than whatever the database
        // hands back: `sections()` carries no ORDER BY, and the prompt asks for
        // one block per section "na mesma ordem" — an order that shifts between
        // runs makes that instruction meaningless and the retries harder to
        // read. Document order is also the order a person expects to see.
        $positions = array_flip(array_map(
            fn (SubmissionSectionKey $key) => $key->value,
            SubmissionSectionKey::cases(),
        ));

        $pending = $submission->sections
            ->filter(fn (SubmissionSection $section) => filled($section->content))
            ->sortBy(fn (SubmissionSection $section) => $positions[$section->key->value] ?? PHP_INT_MAX)
            ->map(fn (SubmissionSection $section) => [
                'key'     => $section->key,
                'content' => (string) $section->content,
            ])
            ->values()
            ->all();

        if ($pending === []) {
            return ['condensed' => [], 'failed' => [], 'attempts' => 0];
        }

        $maxAttempts = max(1, (int) config('services.cati.max_attempts'));
        $accepted = [];
        $problems = [];
        $attempts = 0;

        for ($attempt = 1; $attempt <= $maxAttempts && $pending !== []; $attempt++) {
            $attempts = $attempt;

            $prompt = $attempt === 1
                ? $this->prompts->userPrompt($submission, $pending)
                : $this->prompts->userPrompt($submission, $pending) . "\n\n---\n\n" . $this->prompts->correctionPrompt($problems);

            $blocks = $this->extractBlocks($this->prompt($prompt)->text);

            $problems = [];
            $stillPending = [];

            foreach ($pending as $section) {
                $key = $section['key'];
                $markdown = $blocks[$key->value] ?? null;

                if ($markdown === null) {
                    $problems[$key->value] = ['não veio na resposta'];
                    $stillPending[] = $section;

                    continue;
                }

                $issues = $this->validator->validate($this->markdown->convert($markdown));

                if ($issues !== []) {
                    $problems[$key->value] = $issues;
                    $stillPending[] = $section;

                    continue;
                }

                $accepted[$key->value] = $markdown;
            }

            $pending = $stillPending;
        }

        $this->store($submission, $accepted);

        return [
            'condensed' => array_keys($accepted),
            // Left uncondensed on purpose — the deck prints the full text.
            'failed'   => $problems,
            'attempts' => $attempts,
        ];
    }

    /**
     * @param  array<string, string>  $accepted
     */
    private function store(Submission $submission, array $accepted): void
    {
        foreach ($accepted as $key => $markdown) {
            $sectionKey = SubmissionSectionKey::tryFrom($key);

            if ($sectionKey === null) {
                continue;
            }

            $section = $submission->section($sectionKey);

            $section->update([
                'slide_content' => $markdown,
                // Pinned to the text it was made FROM: editing the section
                // afterwards makes this stale, and the deck falls back.
                'slide_source_hash' => SubmissionSection::hashFor($section->content),
            ]);
        }
    }

    /**
     * @return array<string, string> section key => markdown
     */
    private function extractBlocks(string $text): array
    {
        $blocks = [];

        preg_match_all(self::BLOCK_PATTERN, trim($text), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $blocks[$match[1]] = trim($match[2]);
        }

        return $blocks;
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
