<?php

namespace App\Http\Requests\Concerns;

use App\Models\DocumentationPage;
use App\Models\FlowspecChat;
use App\Models\Integration;
use App\Rules\FlowspecDocumentReference;
use App\Services\Flowspec\FlowspecContextBudget;
use App\Support\Context\TokenEstimator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\UploadedFile;

/**
 * Rules and the context ceiling shared by the two endpoints that can add
 * context to a conversation: creating a chat (which carries whatever was staged
 * in the new-chat composer) and attaching to an existing one.
 *
 * The ceiling is enforced HERE, before anything is ingested, on the raw inputs
 * — not after the fact. Ingesting first and checking later would mean rolling
 * back both DB rows and files already written to disk by MediaLibrary, and a
 * partial rollback of media is exactly the kind of failure that leaves orphans.
 * The estimate is deliberately an over-count (see TokenEstimator), so the guard
 * refuses slightly too eagerly rather than letting a request through that the
 * meter promised would fit.
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
     * Refuses context that wouldn't fit, naming what to do about it. Called
     * from the request's `withValidator`, so the failure arrives as a normal
     * 422 the composer already knows how to surface.
     */
    protected function guardContextBudget(Validator $validator, ?FlowspecChat $chat): void
    {
        $validator->after(function (Validator $validator) use ($chat) {
            $incoming = $this->incomingContextTokens();

            if ($incoming === 0) {
                return;
            }

            $usage = app(FlowspecContextBudget::class)->for($chat);
            $room = $usage->attachableTokens();

            if ($incoming <= $room) {
                return;
            }

            $validator->errors()->add('documents', $this->budgetMessage($room));
        });
    }

    /**
     * Also bounds the COUNT, independent of size: a hundred one-line pastes
     * costs little in tokens but makes every following turn's context list and
     * prompt assembly needlessly heavy.
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

    /** Estimated tokens this request wants to ADD to the conversation's context. */
    private function incomingContextTokens(): int
    {
        $tokens = 0;

        foreach ($this->documentRefs() as $ref) {
            $tokens += TokenEstimator::forChars($this->referencedChars($ref));
        }

        foreach ($this->pastedTexts() as $text) {
            $tokens += TokenEstimator::forText($text['content']);
        }

        foreach ($this->uploadedFiles() as $file) {
            $tokens += TokenEstimator::forUploadedBytes(
                $file->getMimeType(),
                $file->getClientOriginalExtension(),
                (int) $file->getSize(),
            );
        }

        return $tokens;
    }

    /** @param array{type: string, id: int} $ref */
    private function referencedChars(array $ref): int
    {
        $model = $ref['type'] === 'page' ? DocumentationPage::class : Integration::class;

        return mb_strlen((string) $model::query()->whereKey($ref['id'])->value('documentation'));
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

    private function budgetMessage(int $room): string
    {
        return $room <= 0
            ? 'O contexto desta conversa já está no limite. Remova algum documento ou arquivo antes de anexar outro.'
            : 'Isso não cabe no limite de contexto desta conversa (resta espaço para cerca de '
                . number_format($room / 1000, 0, ',', '.') . 'k tokens). Remova algo ou anexe menos de uma vez.';
    }
}
