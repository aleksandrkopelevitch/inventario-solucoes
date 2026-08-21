<?php

namespace App\Actions\Flowspec;

use App\Enums\ContextExtractionState;
use App\Enums\FlowspecAttachmentKind;
use App\Models\FlowspecAttachment;
use App\Models\FlowspecChat;
use App\Support\Context\ExtractedText;
use App\Support\Context\NativeAttachmentType;
use App\Support\Context\SensitiveTextScanner;
use App\Support\Context\SourceTextExtractor;
use App\Support\Context\TokenEstimator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores one uploaded file as context for a conversation: media in, text out,
 * credentials flagged — the same partitioning
 * App\Actions\Cati\IngestSubmissionSource does for a submission's material,
 * against the shared App\Support\Context extractors.
 *
 * A PDF or an image is NOT extracted: it rides along to the model as a native
 * attachment, which is recorded as `Skipped`, never as a failure. Its token
 * cost is the one thing this step can measure and the budget can't — nothing
 * downstream sees the file's bytes again.
 */
class AttachFlowspecFile
{
    public function __construct(
        private readonly SourceTextExtractor $extractor,
        private readonly SensitiveTextScanner $scanner,
    ) {}

    public function handle(FlowspecChat $chat, UploadedFile $file): FlowspecAttachment
    {
        // Read before adding the media: addMedia() MOVES the uploaded file, and
        // the UploadedFile no longer answers for it afterwards.
        $label = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();

        $media = $chat
            ->addMedia($file)
            ->toMediaCollection(FlowspecChat::ATTACHMENTS_COLLECTION);

        $extracted = $this->read($media->getPath(), $extension, $label);

        return $chat->attachments()->create([
            'kind'               => FlowspecAttachmentKind::File,
            'label'              => $label,
            'media_id'           => $media->id,
            'content'            => $extracted->text,
            'extraction_state'   => $extracted->state,
            'extraction_note'    => $extracted->note,
            'sensitive_findings' => $extracted->text === null ? null : ($this->scanner->scan($extracted->text) ?: null),
            'token_estimate'     => $this->estimate($extracted, $media->mime_type, $extension, (int) $media->size),
        ]);
    }

    /**
     * What this file will actually cost the context window.
     *
     * `Skipped` is not one thing: SourceTextExtractor returns it both for a
     * PDF/image (deliberately unextracted — it goes to the model natively, and
     * it costs) and for a format it simply can't read (it goes nowhere, and it
     * costs nothing). Only the mime type tells the two apart, so the mime — not
     * the state — is what decides here. A `Failed` read costs nothing for the
     * same reason: there is nothing to send.
     */
    private function estimate(ExtractedText $extracted, ?string $mimeType, ?string $extension, int $bytes): int
    {
        if ($extracted->text !== null) {
            return TokenEstimator::forText($extracted->text);
        }

        $kind = NativeAttachmentType::for($mimeType, $extension);

        return $extracted->state === ContextExtractionState::Skipped && $kind !== null
            ? TokenEstimator::forNativeAttachment($kind, $bytes)
            : 0;
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
            Log::error('flowSpec: attachment extraction failed', ['file' => $label, 'exception' => $e]);

            return ExtractedText::failed('Não foi possível ler o arquivo.');
        }
    }
}
