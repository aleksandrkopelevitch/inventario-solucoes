<?php

namespace App\Services\Flowspec;

use App\Enums\FlowspecAttachmentKind;
use App\Enums\FlowspecDocumentType;
use App\Enums\FlowspecTag;
use App\Models\DocumentationPage;
use App\Models\FlowspecAttachment;
use App\Models\FlowspecChat;
use App\Models\FlowspecExample;
use App\Models\Solution;
use App\Support\Context\NativeAttachmentType;
use App\Support\Digibee\ConnectorDocMap;
use App\Support\Digibee\ConnectorReference;
use App\Support\Digibee\TenantVocabulary;
use App\Support\Fold;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;

/**
 * Turns a conversation's ATTACHED context into what a prompt needs, and
 * suggests what looks missing.
 *
 * The one rule that shapes this whole class: **nothing enters a prompt that
 * nobody attached.** An earlier version inferred Solutions by matching their
 * names in the request text and silently folded up to 60k characters of their
 * documentation into the request. That was invisible spend — the composer's
 * context meter could not show it before sending, so the number a user read and
 * the request they paid for were different numbers. The name matching survives,
 * but now it only produces SUGGESTIONS (`suggestFor()`), one click away from
 * becoming a real attachment the meter can see.
 *
 * No AI is involved in that matching and none is needed: it is a word-boundary
 * scan over the Solution catalog. It runs in PHP rather than SQL because
 * `Str::ascii()` folds "ó"->"o" portably between SQLite (dev) and PostgreSQL
 * (prod) without depending on an extension (`unaccent`) or a driver-specific
 * collation — at the catalog's scale (dozens of Solutions, one query) that
 * trade costs nothing.
 */
class FlowspecContextResolver
{
    public function __construct(private readonly TenantVocabulary $vocabulary = new TenantVocabulary) {}

    public function resolve(FlowspecChat $chat, string $request): FlowspecContext
    {
        $attachments = $chat->attachments()->with('media')->get();

        $pages = $this->referencedDocumentation($attachments);
        [$textDocs, $nativeAttachments, $attachedMeta, $omittedAttachments] = $this->partitionMaterial($attachments);

        $tags = $this->candidateTags($this->normalize($request));
        $referenceFlowspecs = $this->referenceFlowspecs($attachments);

        // What this turn is ABOUT, before the examples are picked: a pasted
        // pipeline and a request naming a component are far better evidence
        // than a keyword-derived tag, and they are what ranks the corpus below.
        $named = $this->namedConnectors($request, $referenceFlowspecs);
        $examples = $this->selectExamples($tags, $named);

        return new FlowspecContext(
            pages: $pages,
            textDocs: $textDocs,
            referenceFlowspecs: $referenceFlowspecs,
            attachments: $nativeAttachments,
            attachedMeta: $attachedMeta,
            omittedAttachments: $omittedAttachments,
            examples: $examples,
            tags: $tags,
            connectors: $this->connectorsInPlay($named, $examples),
        );
    }

    /**
     * The documentation behind `document` attachments, read live from its
     * reference — never a copy, so an edited page is current in every
     * conversation pointing at it on the very next turn.
     *
     * @param  Collection<int, FlowspecAttachment>  $attachments
     * @return Collection<int, DocumentationPage>
     */
    private function referencedDocumentation(Collection $attachments): Collection
    {
        $pageIds = $attachments
            ->where('kind', FlowspecAttachmentKind::Document)
            ->where('reference_type', DocumentationPage::class)
            ->pluck('reference_id')
            ->all();

        return $pageIds === [] ? collect() : DocumentationPage::query()
            ->whereKey($pageIds)
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->with('notebook')
            ->get()
            ->sortBy(fn (DocumentationPage $page) => [$page->notebook_id, $page->position])
            ->values();
    }

    /**
     * Splits the material the user brought into text that gets inlined and
     * files the model reads natively (PDF/image) — the same partitioning
     * App\Services\Documentation\ContextDocumentResolver does for a caderno's
     * context documents.
     *
     * There is no character budget here, deliberately: the attach endpoints
     * already refused anything that wouldn't fit
     * (FlowspecContextBudget::attachableTokens), so whatever is stored is known
     * to fit and silently trimming it again would contradict the meter the user
     * read. Only the AGGREGATE byte ceiling on native attachments still applies
     * — that one is the provider's request limit, not our budget.
     *
     * @param  Collection<int, FlowspecAttachment>  $attachments
     * @return array{Collection<int, array{label: string, content: string}>, list<object>, list<array{id: int, name: string, kind: string}>, list<string>}
     */
    private function partitionMaterial(Collection $attachments): array
    {
        $maxBytes = (int) config('services.flowspec.max_attachment_bytes');

        $textDocs = collect();
        $native = [];
        $attachedMeta = [];
        $omitted = [];
        $bytes = 0;

        foreach ($attachments as $attachment) {
            if ($attachment->kind === FlowspecAttachmentKind::Document || $attachment->is_flowspec_reference) {
                continue; // handled by referencedDocumentation() / referenceFlowspecs()
            }

            if ($attachment->hasInlineText()) {
                $textDocs->push(['label' => $attachment->label, 'content' => (string) $attachment->content]);

                continue;
            }

            $media = $attachment->media;

            if ($media === null) {
                continue;
            }

            $mime = (string) $media->mime_type;

            // Only images and PDFs ride along natively, and this is the SAME
            // decision the ingest step priced the file with
            // (NativeAttachmentType) — a `Skipped` state alone isn't enough,
            // since SourceTextExtractor also returns it for a format it simply
            // can't read, which has nothing to send.
            $kind = NativeAttachmentType::for($mime, $media->extension);

            if ($kind === null) {
                continue;
            }

            if ($maxBytes > 0 && $bytes + (int) $media->size > $maxBytes) {
                $omitted[] = $attachment->label;

                continue;
            }

            $bytes += (int) $media->size;

            $native[] = $kind === NativeAttachmentType::IMAGE
                ? new LocalImage($media->getPath(), $mime)
                : new LocalDocument($media->getPath(), $mime);

            $attachedMeta[] = ['id' => $attachment->id, 'name' => $attachment->label, 'kind' => $kind];
        }

        return [$textDocs, $native, $attachedMeta, $omitted];
    }

    /**
     * Pasted `{meta, flowSpec}` documents, already minified at attach time.
     * They get their own prompt section instead of being mixed in with prose
     * material — see AttachFlowspecText for why this is a flag on a text
     * attachment rather than a third kind of attachment.
     *
     * Each one carries its LABEL, which is the name the person gave it in the
     * context panel. That name is the heading it gets in the prompt, so
     * "estenda o flowSpec Pedidos B2B" resolves to a section the model can
     * actually find. Dropping the label here (which this did) left the prompt
     * numbering them `Pipeline 1..N` instead — a name nobody could see, and so
     * a name nobody could cite.
     *
     * @param  Collection<int, FlowspecAttachment>  $attachments
     * @return Collection<int, array{label: string, content: string}>
     */
    private function referenceFlowspecs(Collection $attachments): Collection
    {
        return $attachments
            ->filter(fn (FlowspecAttachment $a) => $a->is_flowspec_reference && filled($a->content))
            ->map(fn (FlowspecAttachment $a) => ['label' => (string) $a->label, 'content' => (string) $a->content])
            ->values();
    }

    /**
     * Documentation worth attaching, for text that names systems by name —
     * whether that text is the message someone is typing or the assistant's own
     * "I'd need the IAM docs" reply. Each hit becomes an "adicionar ao contexto"
     * button; nothing is added until it's clicked.
     *
     * Never invents a name: it matches against the real Solution catalog, and
     * skips anything the conversation already has attached.
     *
     * @param  list<string>  $attachedKeys  `type:id` references already in the context
     * @return list<array{type: string, id: int, label: string}>
     */
    public function suggestFor(string $text, array $attachedKeys = []): array
    {
        $mentioned = $this->matchSolutions($this->normalize($text));

        if ($mentioned->isEmpty()) {
            return [];
        }

        // Only the columns used in the labels — never the longText
        // `documentation` (it's only used in the WHERE), since this runs on
        // every conversational reply and on composer keystrokes.
        // Through the caderno, not off the page: a page reaches a Solution via
        // `notebook.solutions` now, and the same page legitimately answers for
        // several of the mentioned systems at once.
        $pages = DocumentationPage::query()
            ->whereHas('notebook.solutions', fn ($q) => $q->whereKey($mentioned->modelKeys()))
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->with('notebook:id,name')
            ->orderBy('position')
            ->get(['id', 'notebook_id', 'title']);

        // collect($model->all()) before ->map(): mapping an empty
        // Eloquent\Collection doesn't downgrade it to a Support\Collection.
        return collect($pages->all())
            ->map(fn (DocumentationPage $page) => [
                'type'  => 'page',
                'id'    => $page->id,
                'label' => "{$page->notebook?->name} — {$page->title}",
            ])
            ->reject(fn (array $ref) => in_array($this->morphKey($ref), $attachedKeys, true))
            ->take((int) config('services.flowspec.max_suggested_documents'))
            ->values()
            ->all();
    }

    /**
     * The `type:id` keys a chat already has attached, in the shape suggestFor()
     * rejects against.
     *
     * @return list<string>
     */
    public function attachedKeys(FlowspecChat $chat): array
    {
        return $chat->attachments()
            ->where('kind', FlowspecAttachmentKind::Document)
            ->get(['reference_type', 'reference_id'])
            ->map(fn (FlowspecAttachment $a) => "{$a->reference_type}:{$a->reference_id}")
            ->all();
    }

    /**
     * Picker reference (`page:12`) -> stored morph key
     * (`App\Models\DocumentationPage:12`).
     *
     * @param  array{type: string, id: int}  $ref
     */
    private function morphKey(array $ref): string
    {
        return FlowspecDocumentType::from($ref['type'])->morphKey($ref['id']);
    }

    /**
     * Solutions whose name appears as a whole word in the text.
     *
     * @return Collection<int, Solution>
     */
    private function matchSolutions(string $normalizedText): Collection
    {
        return Solution::query()
            ->get(['id', 'name'])
            ->filter(function (Solution $solution) use ($normalizedText) {
                $name = $this->normalize($solution->name);

                return $name !== ''
                    && preg_match('/(?<![a-z0-9])' . preg_quote($name, '/') . '(?![a-z0-9])/', $normalizedText) === 1;
            })
            ->values();
    }

    /** @return list<string> */
    private function candidateTags(string $normalizedRequest): array
    {
        $tags = [];

        foreach (FlowspecTag::cases() as $tag) {
            foreach ($tag->keywords() as $keyword) {
                if (preg_match('/(?<![a-z0-9])' . preg_quote($keyword, '/') . '(?![a-z0-9])/', $normalizedRequest) === 1) {
                    $tags[] = $tag->value;

                    break;
                }
            }
        }

        return $tags;
    }

    /**
     * Connector names this turn NAMES, most certain first.
     *
     * Two sources, and both are statements rather than guesses: a pipeline the
     * user pasted uses the connectors it uses, and a request that writes
     * `object-store-connector` means that connector. Deliberately not derived
     * from tags — a tag is a keyword match on prose, which is fine for ranking
     * examples and far too loose to decide which component's parameter
     * reference gets 2 KB of the prompt.
     *
     * @param  Collection<int, array{label: string, content: string}>  $referenceFlowspecs
     * @return list<string>
     */
    private function namedConnectors(string $request, Collection $referenceFlowspecs): array
    {
        $pasted = $this->vocabulary->connectorsMentionedIn(
            $referenceFlowspecs->pluck('content')->implode(' ')
        );

        return array_values(array_unique([
            ...$pasted,
            ...$this->vocabulary->connectorsMentionedIn($request),
            ...$this->catalogConnectorsIn($request),
            ...$this->titledConnectorsIn($request),
        ]));
    }

    /**
     * Connectors the request names the way a PERSON names them — by the title
     * on the card, which is the label Digibee prints on the canvas.
     *
     * Nobody types `object-store-connector`; they write "guarda no Object
     * Store". Without this the only high-confidence signal was the JSON name,
     * so a request that said exactly what it wanted still fell through to
     * ranking by how rare a connector is across our pipelines — which is a
     * decent proxy and a poor substitute for being told.
     *
     * The version suffix is tried both ways ("REST V2" and "REST"), since the
     * canvas shows the version and a sentence rarely does. Folded on both
     * sides and matched on word boundaries, like every other search in this app
     * (§ Searching — `whereFolded()`).
     *
     * @return list<string>
     */
    private function titledConnectorsIn(string $request): array
    {
        $folded = Fold::text($request);
        $reference = new ConnectorReference;
        $found = [];

        foreach (ConnectorDocMap::connectors() as $connector) {
            $title = $reference->card($connector)?->title;

            if ($title === null) {
                continue;
            }

            $candidates = array_unique(array_filter([
                $title,
                preg_replace('/\s+V\d+$/i', '', $title),
            ], fn (?string $candidate) => $candidate !== null && mb_strlen($candidate) >= 3));

            foreach ($candidates as $candidate) {
                $pattern = '/(?<![a-z0-9])' . preg_quote(Fold::text($candidate), '/') . '(?![a-z0-9])/';

                if (preg_match($pattern, $folded) === 1) {
                    $found[] = $connector;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Catalog connectors written out in the request, for the ones our own
     * pipelines have never used — the vocabulary only knows the 31 connectors
     * that appear in the export, and the catalog has 34.
     *
     * @return list<string>
     */
    private function catalogConnectorsIn(string $request): array
    {
        return array_values(array_filter(
            ConnectorDocMap::connectors(),
            fn (string $connector) => str_contains($request, $connector),
        ));
    }

    /**
     * The connectors whose reference the prompt will carry: what the request
     * named, then what the chosen examples use.
     *
     * The examples are a weaker signal deliberately placed second — they are
     * how a request that names no component at all ("preciso ler um arquivo do
     * SFTP e gravar no BigQuery") still gets the right cards, since the corpus
     * example it pulled is made of exactly those connectors.
     *
     * @param  list<string>  $named
     * @param  Collection<int, FlowspecExample>  $examples
     * @return list<string>
     */
    private function connectorsInPlay(array $named, Collection $examples): array
    {
        // RAREST FIRST, and that ordering is the whole value of this list.
        // An example is a whole pipeline, so it drags in the plumbing every
        // pipeline has — `log-connector` and `throw-error-connector` appear in
        // almost all 176 of ours. In step order they came out ahead of the
        // connectors the request was actually about: "ler um arquivo do SFTP e
        // gravar no BigQuery" filled its six card slots with log, throw-error
        // and json-generator, and BigQuery never got one. How often we use a
        // connector is exactly how little it distinguishes this request from
        // any other, and the tenant vocabulary already counts it.
        $fromExamples = $examples
            ->flatMap(fn (FlowspecExample $example) => $this->connectorsOf($example->flow_spec))
            ->unique()
            ->sortBy(fn (string $connector) => $this->vocabulary->forConnector($connector)['uses'] ?? 0)
            ->all();

        return array_values(array_unique([...$named, ...$fromExamples]));
    }

    /**
     * Connector names used by a `{meta, flowSpec}` document.
     *
     * @param  array<string, mixed>|null  $document
     * @return list<string>
     */
    private function connectorsOf(?array $document): array
    {
        $names = [];

        foreach ($document['flowSpec'] ?? [] as $steps) {
            foreach (is_array($steps) ? $steps : [] as $step) {
                if (is_array($step) && ($step['type'] ?? null) === 'connector' && is_string($step['name'] ?? null)) {
                    $names[$step['name']] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * The 2-3 examples closest to the request — more than that dilutes the
     * signal and wastes tokens. Fallback: the generic anchor example.
     *
     * Ranked by CONNECTOR overlap first and tag overlap second. Tags are a
     * keyword scan over prose, so three examples could legitimately share no
     * component at all with what was asked for; a connector the request or a
     * pasted pipeline actually names is a fact, and an example using it teaches
     * the param shape the request is going to need.
     *
     * @param  list<string>  $tags
     * @param  list<string>  $connectors
     * @return Collection<int, FlowspecExample>
     */
    private function selectExamples(array $tags, array $connectors = []): Collection
    {
        $limit = (int) config('services.flowspec.max_examples');

        $examples = $tags === [] && $connectors === []
            ? collect()
            : FlowspecExample::query()->active()
                ->when($tags !== [], fn ($query) => $query->withAnyTag($tags))
                ->get()
                ->sortByDesc(fn (FlowspecExample $example) => [
                    count(array_intersect($this->connectorsOf($example->flow_spec), $connectors)),
                    count(array_intersect($example->tags, $tags)),
                ])
                ->take($limit)
                ->values();

        if ($examples->isEmpty()) {
            $examples = FlowspecExample::query()
                ->active()
                ->where('slug', config('services.flowspec.fallback_example'))
                ->get();
        }

        return $examples;
    }

    /** Lowercase and unaccented, to match keyword and Solution name. */
    private function normalize(string $text): string
    {
        return mb_strtolower(Str::ascii($text));
    }
}
