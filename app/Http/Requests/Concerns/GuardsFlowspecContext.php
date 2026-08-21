<?php

namespace App\Http\Requests\Concerns;

use App\Models\FlowspecChat;
use App\Rules\FlowspecDocumentReference;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\UploadedFile;

/**
 * Rules and limits shared by the two endpoints that can add context to a
 * conversation: creating a chat (which carries whatever was staged in the
 * new-chat composer) and attaching to an existing one.
 *
 * Limits are enforced HERE, before anything is ingested. Ingesting first and
 * checking later would mean rolling back both DB rows and files already written
 * to disk by MediaLibrary, and a partial rollback of media is exactly the kind
 * of failure that leaves orphans.
 */
trait GuardsFlowspecContext
{
    /**
     * Formats accepted as context. `file`-based, not `image`: the whole point is
     * that a contract PDF or an exported spec is readable material. SVG rides
     * along for the same reason documentation media accepts it — it is a diagram
     * format, and it is never served back from the public disk.
     */
    private const ACCEPTED_MIMES = 'pdf,pptx,docx,txt,md,csv,json,yaml,yml,xml,png,jpg,jpeg,webp,svg';

    /** @return array<string, mixed> */
    protected function contextRules(): array
    {
        $max = (int) config('services.flowspec.max_attachments');

        return [
            // Inventory documentation, as `page:{id}` / `integration:{id}`
            // references from the picker. `max` bounds the DB lookups one
            // request can trigger at validation time.
            'documents'   => ['nullable', 'array', 'max:' . $max],
            'documents.*' => ['string', new FlowspecDocumentReference],

            'files'   => ['nullable', 'array', 'max:' . $max],
            'files.*' => ['file', 'mimes:' . self::ACCEPTED_MIMES, 'max:20480'],

            // Long pastes turned into text attachments by the composer. Its own
            // (large) ceiling: a full pasted pipeline JSON easily exceeds the
            // prose `message` cap of 8000.
            'texts'           => ['nullable', 'array', 'max:' . $max],
            'texts.*.content' => ['required', 'string', 'max:' . config('services.flowspec.max_reference_chars')],
            'texts.*.label'   => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    protected function contextMessages(): array
    {
        return [
            'files.*.mimes' => 'Formato não aceito. Envie PDF, PPTX, DOCX, texto, planilha CSV ou imagem.',
            'files.*.max'   => 'O arquivo passa de 20 MB.',
            'file.mimes'    => 'Formato não aceito. Envie PDF, PPTX, DOCX, texto, planilha CSV ou imagem.',
            'file.max'      => 'O arquivo passa de 20 MB.',
        ];
    }

    /**
     * Bounds how many items one conversation's context may hold. A hundred
     * one-line pastes is cheap to store but makes every following turn's context
     * list and prompt assembly needlessly heavy.
     */
    protected function guardContextCount(Validator $validator, ?FlowspecChat $chat): void
    {
        $validator->after(function (Validator $validator) use ($chat) {
            $max = (int) config('services.flowspec.max_attachments');
            $existing = $chat?->attachments()->count() ?? 0;
            $incoming = count($this->input('documents', []))
                + count($this->input('texts', []))
                + count($this->allFiles()['files'] ?? [])
                + (int) $this->hasFile('file')
                + (int) filled($this->input('text'));

            if ($existing + $incoming > $max) {
                $validator->errors()->add(
                    'documents',
                    "Esta conversa já usa o máximo de {$max} itens de contexto. Remova algum antes de anexar outro."
                );
            }
        });
    }

    /**
     * The picker's `page:12` strings, parsed. Reads the raw input rather than
     * `validated()`: this runs inside `withValidator`, before validation has
     * produced a validated set — but only after FlowspecDocumentReference has
     * already rejected anything malformed, so the shape is safe to split here.
     *
     * @return list<array{type: string, id: int}>
     */
    public function documentRefs(): array
    {
        return collect($this->input('documents', []))
            ->filter(fn ($ref) => is_string($ref) && str_contains($ref, ':'))
            ->map(function (string $ref) {
                [$type, $id] = explode(':', $ref, 2);

                return ['type' => $type, 'id' => (int) $id];
            })
            ->values()
            ->all();
    }

    /**
     * Pasted text attachments. Accepts both shapes the two endpoints use: a
     * `texts[]` array (the new-chat composer, which stages several) and a
     * single `text`/`label` pair (attaching to an existing chat, one paste at a
     * time).
     *
     * @return list<array{content: string, label: ?string}>
     */
    public function pastedTexts(): array
    {
        $texts = collect($this->input('texts', []))
            ->filter(fn ($item) => is_array($item) && filled($item['content'] ?? null))
            ->map(fn (array $item) => ['content' => (string) $item['content'], 'label' => $item['label'] ?? null]);

        if (filled($this->input('text'))) {
            $texts->push(['content' => (string) $this->input('text'), 'label' => $this->input('label')]);
        }

        return $texts->values()->all();
    }

    /**
     * Uploaded files, from either endpoint's shape (`files[]` or a single
     * `file`).
     *
     * @return list<UploadedFile>
     */
    public function uploadedFiles(): array
    {
        $files = collect($this->allFiles()['files'] ?? [])
            ->filter(fn ($file) => $file instanceof UploadedFile);

        if ($this->hasFile('file')) {
            $files->push($this->file('file'));
        }

        return $files->values()->all();
    }
}
