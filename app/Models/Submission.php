<?php

namespace App\Models;

use App\Enums\ChainNodeKind;
use App\Enums\SubmissionDiagramKind;
use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use App\Enums\SubmissionStatus;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A submission to the IT Architecture Committee (CATI).
 *
 * The record is authored once and rendered many times — the Leo Resolve ticket
 * text, the Markdown document, and (Fase 2) the deck. Nothing is ever typed
 * into two of those.
 */
class Submission extends Model implements HasMedia
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory, InteractsWithMedia;

    /**
     * Gathered material uploaded to the submission — old decks, PDFs, images,
     * spreadsheets.
     *
     * The app's FIFTH media collection, and deliberately not one of the four
     * that already exist: `avatar` and the logos are images, `docs` is media
     * embedded in Markdown and served by `files.show`, and
     * `context_documents` belongs to a Solution. These files are neither
     * embedded nor image-only (`.pptx`/`.docx`/`.pdf`), and they are served by
     * their own controller. No conversions — nothing renders a thumbnail of a
     * deck.
     */
    public const SOURCES_COLLECTION = 'submission_sources';

    protected $fillable = [
        'name',
        'slug',
        'solution_id',
        'requester_person_id',
        'created_by_id',
        'status',
        'ticket_reference',
        'committee_date',
        'decision',
        'conditions',
        'decided_at',
        'decided_by_id',
        'promoted_at',
        'pre_review',
        'pre_review_requested_at',
        'pre_reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'                  => SubmissionStatus::class,
            'committee_date'          => 'date',
            'conditions'              => 'array',
            'decided_at'              => 'datetime',
            'promoted_at'             => 'datetime',
            'pre_review'              => 'array',
            'pre_review_requested_at' => 'datetime',
            'pre_reviewed_at'         => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::SOURCES_COLLECTION);
    }

    public function solution(): BelongsTo
    {
        return $this->belongsTo(Solution::class);
    }

    /** The person the committee asks questions to — not necessarily the app user who typed it. */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'requester_person_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    /**
     * Conditions the committee attached to an approval, still open.
     *
     * "Aprovada com ressalvas" is only worth anything if the ressalvas are
     * trackable afterwards — one buried in a paragraph is one nobody follows
     * up on.
     *
     * @return list<array{text: string, done: bool}>
     */
    public function openConditions(): array
    {
        return array_values(array_filter(
            $this->conditions ?? [],
            fn (array $condition) => ! ($condition['done'] ?? false),
        ));
    }

    /**
     * How long a pre-review may legitimately be "running" before it counts as
     * dead — same reasoning as SubmissionChat::REPLY_STALL_SECONDS: sized past
     * the job timeout so a worker killed mid-run doesn't leave the button
     * disabled forever.
     */
    public const PRE_REVIEW_STALL_SECONDS = 660;

    public function isPreReviewRunning(): bool
    {
        return $this->pre_review_requested_at !== null
            && ($this->pre_reviewed_at === null || $this->pre_reviewed_at->lessThan($this->pre_review_requested_at))
            && $this->pre_review_requested_at->greaterThan(now()->subSeconds(self::PRE_REVIEW_STALL_SECONDS));
    }

    /** @return list<array{severity: string, section: string, text: string}> */
    public function preReviewFindings(): array
    {
        return $this->pre_review['findings'] ?? [];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(SubmissionSection::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(SubmissionSource::class)->orderBy('id');
    }

    public function chats(): HasMany
    {
        return $this->hasMany(SubmissionChat::class);
    }

    public function diagrams(): HasMany
    {
        return $this->hasMany(SubmissionDiagram::class);
    }

    /**
     * The TO BE this submission's approval blessed, if it had one.
     *
     * HasOne, not HasMany: a submission is deliberated once. See
     * `ApprovedTopology` for why the approval records a pending row instead of
     * writing the catalog outright.
     */
    public function approvedTopology(): HasOne
    {
        return $this->hasOne(ApprovedTopology::class);
    }

    /**
     * The row for one drawing, creating it if it doesn't exist yet.
     *
     * A DRAWN kind is born with the same single root node a Diagram gets
     * (`DiagramController::store()`), for the same reason: the F3
     * canvas draws a chain, and a chain with no nodes has nothing to hang the
     * first gesture on. The root is the linked catalog Solution when there is
     * one — the proposal is about that system, so making the person place it
     * by hand is asking for something already on the record — and free text
     * carrying the submission's own name when there isn't (a brand-new
     * system, which is most of what reaches this committee).
     *
     * An UPLOADED kind gets no chain at all; it carries a picture instead.
     */
    public function diagram(SubmissionDiagramKind $kind): SubmissionDiagram
    {
        return $this->diagrams()->firstOrCreate(
            ['kind' => $kind->value],
            ['chain' => $kind->isDrawn() ? $this->seedChain() : null],
        );
    }

    /**
     * Creates the four rows still missing, in one insert.
     *
     * Idempotent, and called when the diagrams surface is first opened rather
     * than at creation: four empty rows on every submission ever created would
     * be four rows nobody asked for, and the enum can grow a fifth kind later
     * without a data migration.
     */
    public function ensureDiagrams(): void
    {
        // toBase(): Eloquent's pluck() runs the column through the model's
        // casts, so `pluck('kind')` hands back enum instances and the strict
        // in_array() below would never match a string — the same trap
        // `ensureSections()` documents.
        $existing = $this->diagrams()->toBase()->pluck('kind')->all();

        foreach (SubmissionDiagramKind::cases() as $kind) {
            if (! in_array($kind->value, $existing, true)) {
                $this->diagram($kind);
            }
        }
    }

    /** @return array{nodes: list<array<string, mixed>>, edges: list<mixed>} */
    private function seedChain(): array
    {
        $this->loadMissing('solution');

        return [
            'nodes' => [[
                'solution_id' => $this->solution_id,
                'label'       => $this->solution_id ? null : $this->name,
                'kind'        => ChainNodeKind::System->value,
            ]],
            'edges' => [],
        ];
    }

    /**
     * The row for one section, creating it if it doesn't exist yet.
     *
     * `firstOrCreate` rather than a plain read because a section key added to
     * the enum later must appear on submissions that were created before it —
     * the alternative is a data migration every time the committee's form
     * changes. Safe under concurrency thanks to the unique
     * `(submission_id, key)` index.
     */
    public function section(SubmissionSectionKey $key): SubmissionSection
    {
        // `state` is passed explicitly rather than left to the column default:
        // the DB default only reaches the row, not the instance this returns,
        // so the caller would get a model whose `state` is null until it is
        // re-read.
        return $this->sections()->firstOrCreate(
            ['key' => $key->value],
            ['state' => SubmissionSectionState::Empty->value],
        );
    }

    /**
     * Creates the rows still missing for this submission, in one insert.
     *
     * Called right after a submission is created so the detail page has all
     * eleven cards to render, and idempotent so it can be called again after
     * the enum grows.
     */
    public function ensureSections(): void
    {
        // toBase(): Eloquent's pluck() runs the column through the model's
        // casts, so `pluck('key')` would hand back SubmissionSectionKey
        // instances and the strict in_array() below would never match a
        // string — re-inserting all eleven rows and hitting the unique index.
        $existing = $this->sections()->toBase()->pluck('key')->all();

        $missing = array_values(array_filter(
            SubmissionSectionKey::cases(),
            fn (SubmissionSectionKey $key) => ! in_array($key->value, $existing, true),
        ));

        if ($missing === []) {
            return;
        }

        $now = now();

        $this->sections()->insert(array_map(fn (SubmissionSectionKey $key) => [
            'submission_id' => $this->getKey(),
            'key'           => $key->value,
            'state'         => SubmissionSectionState::Empty->value,
            'created_at'    => $now,
            'updated_at'    => $now,
        ], $missing));
    }

    /**
     * Catalog filtering. Shares the contract of `Solution::scopeFilter()` so
     * the index slot, the results counter and the filter chips all recompute
     * from one query instead of re-deriving the conditions three times.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(fn (Builder $w) => $w
                ->whereFolded('submissions.name', $search)
                ->orWhereHas('solution', fn (Builder $s) => $s->whereFolded('name', $search))
                ->orWhereFolded('submissions.ticket_reference', $search)))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['solution'] ?? null, fn (Builder $q, $v) => $q->whereHas('solution', fn (Builder $s) => $s->where('slug', $v)));
    }
}
