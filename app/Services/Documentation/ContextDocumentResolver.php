<?php

namespace App\Services\Documentation;

use App\Models\Notebook;
use Illuminate\Support\Collection;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Resolves a caderno's selected context documents (`Notebook::CONTEXT_COLLECTION`)
 * into what a generation prompt actually needs: text files inlined (respecting
 * a character budget), PDFs/images as native attachments (laravel/ai), and
 * everything left out flagged rather than silently dropped. Shared by every
 * Documentation Assistant turn (`DocumentationChatService`) — extracted from
 * the old one-shot `DocumentationDraftService` unchanged, since a chat turn
 * needs exactly the same partitioning for whichever context docs are checked
 * on that turn.
 */
class ContextDocumentResolver
{
    /** Extensions treated as text inlined into the prompt. */
    private const TEXT_EXTENSIONS = ['txt', 'md', 'csv', 'json', 'yaml', 'yml'];

    /** @param  list<int>  $mediaIds */
    public function resolve(Notebook $notebook, array $mediaIds): ContextDocumentSet
    {
        // Defensive cap (the request doesn't limit media_ids): keeps the first
        // N in collection order and flags the surplus in `omittedContext` —
        // the user selected them explicitly, so they shouldn't vanish without
        // a record (same treatment as `omittedAttachments`).
        $max = (int) config('services.documentation_ai.max_context_documents');
        $selectedAll = $this->selectedMedia($notebook, $mediaIds);
        $selected = $selectedAll->take($max)->values();
        $omittedContext = $selectedAll->slice($max)->pluck('file_name')->values()->all();

        return $this->partition($selected, $omittedContext);
    }

    /**
     * Chosen context documents, in collection order (the `max_context_documents`
     * cap is applied by the caller, so it can flag the surplus). Empty list =
     * NO documents: the panel's checkboxes come checked by default, so `[]`
     * only happens when the user deliberately unchecked all of them — treating
     * it as "all" would silently ignore that choice.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Media>
     */
    private function selectedMedia(Notebook $notebook, array $ids): Collection
    {
        $ids = array_map(intval(...), $ids);

        return $notebook->getMedia(Notebook::CONTEXT_COLLECTION)
            ->whereIn('id', $ids)
            ->values();
    }

    /**
     * Splits into text documents (inlined, respecting the character budget)
     * and native attachments (PDF/image).
     *
     * @param  Collection<int, Media>  $media
     * @param  list<string>  $omittedContext
     */
    private function partition(Collection $media, array $omittedContext): ContextDocumentSet
    {
        $budget = (int) config('services.documentation_ai.doc_budget_chars');
        $maxAttachmentBytes = (int) config('services.documentation_ai.max_attachment_bytes');
        $textDocs = collect();
        $attachments = [];
        $attachedMeta = [];
        $omittedAttachments = [];
        $omittedTexts = [];
        $attachmentBytes = 0;

        foreach ($media as $item) {
            $ext = strtolower((string) $item->extension);
            $mime = (string) $item->mime_type;

            if (in_array($ext, self::TEXT_EXTENSIONS, true) || str_starts_with($mime, 'text/')) {
                if ($budget <= 0) {
                    // Budget exhausted — omits the remaining texts, but records
                    // it: the user marked them on purpose, they can't vanish
                    // without notice (same treatment as omittedAttachments/omittedContext).
                    $omittedTexts[] = $item->file_name;

                    continue;
                }
                // Reads only what's needed from the file (UTF-8: up to 4
                // bytes/char, so (budget+1)*4 guarantees at least budget+1
                // chars) instead of loading a file up to 20MB whole into
                // memory just to truncate it.
                $content = (string) file_get_contents($item->getPath(), false, null, 0, ($budget + 1) * 4);
                if (mb_strlen($content) > $budget) {
                    $content = mb_substr($content, 0, $budget) . "\n\n[documento truncado]";
                }
                $budget -= mb_strlen($content);
                $textDocs->push(['name' => $item->file_name, 'content' => $content]);

                continue;
            }

            $isImage = str_starts_with($mime, 'image/');
            $isPdf = $mime === 'application/pdf' || $ext === 'pdf';

            if (! $isImage && ! $isPdf) {
                continue;
            }

            // Aggregate byte ceiling: exceeded, omit this one and flag it —
            // each file is already <= 20MB per the request's validation, so
            // the first attachment always fits under the API's ~32MB/request limit.
            if ($maxAttachmentBytes > 0 && $attachmentBytes + (int) $item->size > $maxAttachmentBytes) {
                $omittedAttachments[] = $item->file_name;

                continue;
            }

            $attachmentBytes += (int) $item->size;

            if ($isImage) {
                $attachments[] = new LocalImage($item->getPath(), $mime);
                $attachedMeta[] = ['id' => $item->id, 'name' => $item->file_name, 'kind' => 'image'];
            } else {
                $attachments[] = new LocalDocument($item->getPath(), $mime);
                $attachedMeta[] = ['id' => $item->id, 'name' => $item->file_name, 'kind' => 'pdf'];
            }
        }

        return new ContextDocumentSet($textDocs, $attachments, $attachedMeta, $omittedAttachments, $omittedTexts, $omittedContext);
    }
}
