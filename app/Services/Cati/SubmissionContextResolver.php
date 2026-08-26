<?php

namespace App\Services\Cati;

use App\Models\CatiExample;
use App\Models\CatiGuideline;
use App\Models\Submission;
use App\Models\SubmissionSource;
use App\Support\Cati\DeviationRules;
use App\Support\Cati\SubmissionRequirements;
use Illuminate\Support\Collection;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;

/**
 * Assembles one turn's context: what the catalog already knows, what the
 * committee would ask, the gathered material, and the curated corpus.
 *
 * The material is already partitioned by the time it gets here — Block B
 * extracted text at UPLOAD time, so this never re-reads an Office file. Only
 * PDFs and images still need their path, to ride along as native attachments.
 *
 * Anything left out of a turn (budget, caps) is REPORTED, never dropped in
 * silence: the user attached it on purpose.
 */
class SubmissionContextResolver
{
    public function resolve(Submission $submission): SubmissionContext
    {
        $submission->loadMissing(['sections', 'sources.media', 'solution.vendor', 'solution.diagrams']);

        [$textSources, $attachments, $attachedMeta, $omitted] = $this->partitionSources($submission);

        [$examples, $byTag] = $this->selectExamples($submission);

        return new SubmissionContext(
            requirements: SubmissionRequirements::for($submission),
            deviations: DeviationRules::for($submission),
            textSources: $textSources,
            attachments: $attachments,
            attachedMeta: $attachedMeta,
            omittedSources: $omitted,
            guidelines: CatiGuideline::query()->active()->orderBy('id')->get(),
            examples: $examples,
            examplesByTag: $byTag,
        );
    }

    /**
     * @return array{0: Collection<int, array{label: string, text: string, flagged: list<string>}>, 1: list<object>, 2: list<array{id: int, name: string, kind: string}>, 3: list<string>}
     */
    private function partitionSources(Submission $submission): array
    {
        $budget = (int) config('services.cati.doc_budget_chars');
        $maxAttachmentBytes = (int) config('services.cati.max_attachment_bytes');

        $textSources = collect();
        $attachments = [];
        $attachedMeta = [];
        $omitted = [];
        $attachmentBytes = 0;

        foreach ($submission->sources as $source) {
            if ($source->hasText()) {
                [$budget, $omittedLabel] = $this->pushText($textSources, $source, $budget);

                if ($omittedLabel !== null) {
                    $omitted[] = $omittedLabel;
                }

                continue;
            }

            $media = $source->media;

            if ($media === null) {
                continue;
            }

            $mime = (string) $media->mime_type;
            $isImage = str_starts_with($mime, 'image/');
            $isPdf = $mime === 'application/pdf' || mb_strtolower((string) $media->extension) === 'pdf';

            if (! $isImage && ! $isPdf) {
                continue;
            }

            if ($maxAttachmentBytes > 0 && $attachmentBytes + (int) $media->size > $maxAttachmentBytes) {
                $omitted[] = $source->label;

                continue;
            }

            $attachmentBytes += (int) $media->size;

            $attachments[] = $isImage
                ? new LocalImage($media->getPath(), $mime)
                : new LocalDocument($media->getPath(), $mime);

            $attachedMeta[] = ['id' => $media->id, 'name' => $source->label, 'kind' => $isImage ? 'image' : 'pdf'];
        }

        return [$textSources, $attachments, $attachedMeta, $omitted];
    }

    /**
     * @param  Collection<int, array{label: string, text: string, flagged: list<string>}>  $textSources
     * @return array{0: int, 1: string|null} remaining budget, and the label omitted (if any)
     */
    private function pushText(Collection $textSources, SubmissionSource $source, int $budget): array
    {
        if ($budget <= 0) {
            return [$budget, $source->label];
        }

        $text = (string) $source->extracted_text;

        if (mb_strlen($text) > $budget) {
            $text = mb_substr($text, 0, $budget) . "\n\n[material truncado]";
        }

        $textSources->push([
            'label' => $source->label,
            'text'  => $text,
            // The scanner already found these and the UI already badges them.
            // The prompt is the third reader that needs to know: it inlines the
            // raw text either way, and a draft that quotes a `client_secret`
            // into a section gets promoted into the Solution's documentation
            // and printed on a slide (PromoteApprovedSubmission).
            'flagged' => array_values(array_unique(array_column(
                $source->sensitive_findings ?? [],
                'type',
            ))),
        ]);

        return [$budget - mb_strlen($text), null];
    }

    /**
     * Past approved submissions to show as examples, chosen by tag overlap with
     * what the catalog says about this Solution.
     *
     * Falls back to the most recent ones when nothing matches: a good example
     * of the SHAPE of an approved submission is still worth more than none.
     * Which of the two happened is recorded, so a reply can't quietly imply
     * the examples were relevant when they were merely recent.
     *
     * @return array{0: Collection<int, CatiExample>, 1: bool}
     */
    private function selectExamples(Submission $submission): array
    {
        $max = (int) config('services.cati.max_examples');
        $solution = $submission->solution;

        $candidateTags = array_values(array_filter([
            $solution?->category,
            $solution?->cloud,
            $solution?->environment,
        ]));

        $active = CatiExample::query()->active()->latest('id')->get();

        if ($candidateTags !== []) {
            $matched = $active->filter(
                fn (CatiExample $example) => array_intersect($candidateTags, $example->tags ?? []) !== [],
            )->take($max)->values();

            if ($matched->isNotEmpty()) {
                return [$matched, true];
            }
        }

        return [$active->take($max)->values(), false];
    }
}
