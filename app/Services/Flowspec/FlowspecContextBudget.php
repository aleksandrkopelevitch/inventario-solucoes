<?php

namespace App\Services\Flowspec;

use App\Enums\FlowspecAttachmentKind;
use App\Models\DocumentationPage;
use App\Models\FlowspecAttachment;
use App\Models\FlowspecChat;
use App\Models\FlowspecExample;
use App\Models\Integration;
use App\Support\Context\TokenEstimator;
use Illuminate\Support\Collection;

/**
 * Measures what a conversation's next request will cost, so the composer can
 * show it and the attach endpoints can refuse to make it worse.
 *
 * Every number here is an ESTIMATE (see TokenEstimator) — the meter's job is to
 * stop runaway spend, not to bill anyone. Three deliberate choices:
 *
 * - **A `document` attachment is measured LIVE, from its reference.** Its text
 *   is never copied into the row, so a cached size would drift the moment
 *   someone edits the page. One `SUM(LENGTH(...))` per referenced type keeps
 *   this a two-query operation regardless of how many pages are attached.
 * - **A `file`/`text` attachment uses its stored `token_estimate`.** That
 *   content is immutable once ingested, and a native attachment (PDF/image) has
 *   no text to measure at all — only bytes, which only the ingest step sees.
 * - **The corpus line assumes the WORST case**, the `max_examples` largest
 *   active examples, because which examples a request will pull depends on the
 *   text of a message that hasn't been typed yet. A meter that guesses low is
 *   the failure it exists to prevent.
 */
class FlowspecContextBudget
{
    /** Reference types a `document` attachment may point at, and the column holding their text. */
    private const REFERENCE_TYPES = [
        DocumentationPage::class => 'documentation',
        Integration::class       => 'documentation',
    ];

    public function __construct(private readonly FlowspecPromptBuilder $prompts) {}

    /**
     * @param  int  $pendingTokens  context staged in the composer but not yet
     *                              persisted — the new-chat page, where the chat
     *                              doesn't exist until the first message is sent
     */
    public function for(?FlowspecChat $chat, int $pendingTokens = 0): FlowspecContextUsage
    {
        $attachments = $chat === null ? collect() : $chat->attachments()->get();

        $documents = TokenEstimator::forChars($this->referencedChars($attachments));
        $material = (int) $attachments
            ->filter(fn (FlowspecAttachment $a) => $a->kind !== FlowspecAttachmentKind::Document)
            ->sum('token_estimate');

        $fixedPrompt = TokenEstimator::forText($this->prompts->systemPrompt());
        $corpus = $this->worstCaseCorpusTokens();
        $history = $chat === null ? 0 : $this->historyTokens($chat);

        $limit = (int) config('services.flowspec.context_limit_tokens');
        $reserve = (int) config('services.flowspec.history_reserve_tokens');

        return new FlowspecContextUsage(
            lines: [
                'Regras e catálogo Digibee' => $fixedPrompt,
                'Exemplos do corpus'        => $corpus,
                'Documentos do inventário'  => $documents,
                'Arquivos e textos'         => $material + $pendingTokens,
                'Histórico da conversa'     => $history,
            ],
            attached: $documents + $material + $pendingTokens,
            history: $history,
            fixed: $fixedPrompt + $corpus,
            limit: $limit,
            // Clamped at 0: a misconfigured reserve larger than the limit must
            // not produce a negative ceiling that refuses everything silently.
            attachLimit: max(0, $limit - $reserve),
        );
    }

    /**
     * Characters of documentation behind the `document` attachments, read live.
     *
     * @param  Collection<int, FlowspecAttachment>  $attachments
     */
    private function referencedChars(Collection $attachments): int
    {
        $documents = $attachments->where('kind', FlowspecAttachmentKind::Document);
        $chars = 0;

        foreach (self::REFERENCE_TYPES as $type => $column) {
            $ids = $documents->where('reference_type', $type)->pluck('reference_id')->unique()->all();

            if ($ids === []) {
                continue;
            }

            // COALESCE, not a PHP null guard: with no matching rows SUM() is
            // NULL, and `(int) null` would read as a legitimate zero — masking
            // a reference whose target was deleted rather than costing nothing.
            $chars += (int) $type::query()
                ->whereKey($ids)
                ->selectRaw("COALESCE(SUM(LENGTH({$column})), 0) as total")
                ->value('total');
        }

        return $chars;
    }

    /**
     * The largest examples the corpus could contribute to a single request.
     *
     * Measured in PHP, not with `SUM(LENGTH(flow_spec))`. `flowspec_examples.flow_spec`
     * is a `jsonb` column: PostgreSQL (production) has no `length(jsonb)` and
     * errors outright, while SQLite (dev/test) stores it as TEXT and answers
     * happily — a query that would pass every test and 500 in production. The
     * corpus is curated and small, and FlowspecPromptBuilder::examplesSection()
     * already loads these same rows in full for every prompt, so reading them
     * here costs nothing new.
     */
    private function worstCaseCorpusTokens(): int
    {
        $chars = FlowspecExample::query()
            ->active()
            ->get(['id', 'description', 'flow_spec'])
            ->map(fn (FlowspecExample $example) => mb_strlen(
                (string) json_encode($example->flow_spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ) + mb_strlen((string) $example->description))
            ->sortDesc()
            ->take((int) config('services.flowspec.max_examples'))
            ->sum();

        return TokenEstimator::forChars((int) $chars);
    }

    /**
     * What the conversation so far costs. Mirrors what
     * FlowspecPromptBuilder::historySection() actually embeds: every message's
     * prose, plus the most recent generated flowSpec in full (older ones
     * collapse to a placeholder there, so they must not be counted here).
     */
    private function historyTokens(FlowspecChat $chat): int
    {
        $messages = $chat->messages()->get(['id', 'role', 'content', 'flow_spec']);

        $chars = $messages->sum(fn ($message) => mb_strlen((string) $message->content));

        $latestWithSpec = $messages->last(fn ($message) => $message->flow_spec !== null);

        if ($latestWithSpec !== null) {
            $chars += mb_strlen((string) json_encode($latestWithSpec->flow_spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return TokenEstimator::forChars($chars);
    }
}
