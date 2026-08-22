<?php

namespace App\Actions\Cati;

use App\Enums\ContextExtractionState;
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
 * Stores material behind a submission: text out, credentials flagged.
 *
 * Two paths live here — an uploaded file and a long paste — because both have
 * work to do beyond one assignment. A link or an inventory reference does not:
 * it is a plain `sources()->create(...)` with nothing to extract.
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
     * A long paste from the interview's composer.
     *
     * There is nothing to extract — the text IS the source — but it goes
     * through the same credential scan as an uploaded file, for the same
     * reason: the most likely thing anyone pastes into a box about
     * architecture is a config block, and a config block is where secrets
     * live.
     */
    public function handleText(Submission $submission, string $text, ?string $label = null): SubmissionSource
    {
        return $submission->sources()->create([
            'kind'               => SubmissionSourceKind::Text,
            'label'              => $this->labelFor($text, $label),
            'extracted_text'     => $text,
            'extraction_state'   => ContextExtractionState::Done,
            'sensitive_findings' => $this->scanner->scan($text) ?: null,
        ]);
    }

    /**
     * The paste's first non-blank line, so four pasted blocks are tellable
     * apart in the material list.
     *
     * Derived server-side even though the composer sends one: the label is
     * what the reviewer reads next to a credential warning, and it should not
     * depend on the client having got it right.
     */
    private function labelFor(string $text, ?string $label): string
    {
        $label = trim((string) $label);

        if ($label === '') {
            $lines = preg_split('/\R/u', $text) ?: [];
            $label = trim((string) collect($lines)->first(fn (string $line) => trim($line) !== ''));
        }

        if ($label === '') {
            return 'Texto colado';
        }

        return mb_strlen($label) > 80 ? mb_substr($label, 0, 79) . '…' : $label;
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
