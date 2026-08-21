<?php

namespace App\Actions\Cati;

use App\Enums\SubmissionSourceKind;
use App\Models\Submission;
use App\Models\SubmissionSource;
use App\Support\Context\ExtractedText;
use App\Support\Context\SensitiveTextScanner;
use App\Support\Context\SourceTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores one uploaded file as material behind a submission: media in, text
 * out, credentials flagged.
 *
 * Only the upload path lives here, because it is the only one with work to
 * do. A link or an inventory reference is a plain `sources()->create(...)`
 * with nothing to extract — no action needed to wrap one assignment.
 */
class IngestSubmissionSource
{
    public function __construct(
        private readonly SourceTextExtractor $extractor,
        private readonly SensitiveTextScanner $scanner,
    ) {}

    public function handle(Submission $submission, UploadedFile $file): SubmissionSource
    {
        // Read before adding the media: addMedia() MOVES the uploaded file,
        // and the UploadedFile no longer answers for it afterwards.
        $label = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();

        $media = $submission
            ->addMedia($file)
            ->toMediaCollection(Submission::SOURCES_COLLECTION);

        $extracted = $this->read($media->getPath(), $extension, $label);

        return $submission->sources()->create([
            'kind'               => SubmissionSourceKind::Upload,
            'label'              => $label,
            'media_id'           => $media->id,
            'extracted_text'     => $extracted->text,
            'extraction_state'   => $extracted->state,
            'extraction_note'    => $extracted->note,
            'sensitive_findings' => $extracted->text === null ? null : ($this->scanner->scan($extracted->text) ?: null),
        ]);
    }

    /**
     * A file that can't be read must not lose the upload: the row is still
     * created, marked `Failed`, so the user sees what happened instead of the
     * file silently not being there.
     */
    private function read(string $path, string $extension, string $label): ExtractedText
    {
        try {
            return $this->extractor->extract($path, $extension);
        } catch (Throwable $e) {
            Log::error('CATI: source extraction failed', ['file' => $label, 'exception' => $e]);

            return ExtractedText::failed('Não foi possível ler o arquivo.');
        }
    }
}
