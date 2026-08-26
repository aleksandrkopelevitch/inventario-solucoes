<?php

namespace App\Http\Requests\Concerns;

use App\Enums\FlowspecDocumentType;
use App\Models\FlowspecChat;
use App\Rules\FlowspecDocumentReference;
use App\Services\Flowspec\FlowspecContextBudget;
use App\Services\Flowspec\FlowspecContextResolver;
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
            // Inventory documentation, as `page:{id}` / `diagram:{id}`
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
            $incoming = $this->incomingContextTokens($chat);

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
     *
     * Counted through the same three parsers the controller attaches with, and
     * for a reason beyond symmetry: an `after` callback runs even when the
     * rules above it already failed (`Validator::passes()` fires them
     * unconditionally), so this code sees RAW input. `count($this->input(...))`
     * on a `documents=page:1` scalar — which the `array` rule has already
     * rejected — is a TypeError, i.e. a 500 where the 422 was already written.
     * The parsers each ignore anything that isn't the shape they expect.
     */
    protected function guardContextCount(Validator $validator, ?FlowspecChat $chat): void
    {
        $validator->after(function (Validator $validator) use ($chat) {
            $max = (int) config('services.flowspec.max_attachments');
            $existing = $chat?->attachments()->count() ?? 0;
            $incoming = count($this->newDocumentRefs($chat))
                + count($this->pastedTexts())
                + count($this->uploadedFiles());

            if ($existing + $incoming > $max) {
                $validator->errors()->add(
                    'documents',
                    "Esta conversa já usa o máximo de {$max} itens de contexto. Remova algum antes de anexar outro."
                );
            }
        });
    }

    /** Estimated tokens this request wants to ADD to the conversation's context. */
    private function incomingContextTokens(?FlowspecChat $chat): int
    {
        $tokens = 0;

        foreach ($this->newDocumentRefs($chat) as $ref) {
            $tokens += TokenEstimator::forChars($this->referencedChars($ref));
        }

        foreach ($this->pastedTexts() as $text) {
            $tokens += TokenEstimator::forText($text['content']);
        }

        foreach ($this->uploadedFiles() as $file) {
            // The path, not just the size: an Office file is a zip, and its
            // compressed size is nowhere near what it will cost once read
            // (TokenEstimator::extractableBytes).
            $tokens += TokenEstimator::forUploadedBytes(
                $file->getMimeType(),
                $file->getClientOriginalExtension(),
                (int) $file->getSize(),
                $file->getRealPath() ?: null,
            );
        }

        return $tokens;
    }

    /**
     * The referenced documents this request would actually ADD.
     *
     * Attaching a document twice is idempotent (AttachFlowspecDocuments skips
     * what a conversation already has), so charging the second one against
     * either ceiling measures something that will never be created: a chat can
     * be told its context is full by a request that was about to add nothing,
     * and then answer "isso já estava no contexto" if it isn't. Rare — the
     * picker disables what is attached and suggestFor() never offers it — but
     * a suggestion button rendered before the same page was attached from
     * another tab is exactly that request.
     *
     * @return list<array{type: string, id: int}>
     */
    private function newDocumentRefs(?FlowspecChat $chat): array
    {
        $refs = $this->documentRefs();

        if ($chat === null || $refs === []) {
            return $refs;
        }

        $attached = app(FlowspecContextResolver::class)->attachedKeys($chat);

        // tryFrom, never from(): this reads raw input, and a `documents=foo:1`
        // that FlowspecDocumentReference has already rejected must not become a
        // ValueError out of the callback validating it. An unrecognised type
        // simply isn't attached to anything, so it stays in the count.
        return array_values(array_filter($refs, function (array $ref) use ($attached) {
            $type = FlowspecDocumentType::tryFrom($ref['type']);

            return $type === null || ! in_array($type->morphKey($ref['id']), $attached, true);
        }));
    }

    /** @param array{type: string, id: int} $ref */
    private function referencedChars(array $ref): int
    {
        $type = FlowspecDocumentType::tryFrom($ref['type']);

        if ($type === null) {
            return 0;
        }

        $model = $type->modelClass();

        // LENGTH() would be one query instead of a fetch, but `documentation`
        // is measured in CHARACTERS here and LENGTH() counts bytes on MySQL —
        // the estimate must not change meaning with the driver.
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
