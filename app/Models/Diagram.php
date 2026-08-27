<?php

namespace App\Models;

use App\Actions\SyncDiagramFromChain;
use App\Contracts\ChainCanvas;
use App\Contracts\Documentable;
use App\Enums\DiagramStatus;
use App\Enums\Direction;
use App\Enums\SyncMode;
use Database\Factories\DiagramFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A drawing of a flow — the free graph the F3 canvas authors, and a
 * first-class thing in its own right.
 *
 * This used to be `Integration`: a diagram existed only as one integration's
 * picture, reachable only through a solution that took part in it, and it
 * carried a documentation column of its own. Both of those are gone — a diagram
 * is drawn and named on its own (`/diagrams`).
 *
 * **It has exactly ONE relation, and documentation is not it.** `participants`
 * (the `diagram_solution` pivot, plus `source`/`target`) is DERIVED from the
 * chain by `SyncDiagramFromChain` and answers "which systems does this drawing
 * touch?". It is what the ecosystem map is built from, and nothing but that
 * action may write it — which is what keeps the map a reading of the DRAWINGS
 * rather than of somebody's filing.
 *
 * Prose reaches a diagram by CITING it, as a `{% diagram %}` block in a page's
 * text (see `GitbookRenderer`). There was briefly a `documentation_pages.diagram_id`
 * FK for this; it modelled "this page is the page of that drawing", which is
 * narrower than what people write — a page cites several drawings, beside the
 * paragraphs that need them — and it made the diagram catalog answer a question
 * it had no business answering ("which page explains me?").
 */
class Diagram extends Model implements ChainCanvas
{
    /** @use HasFactory<DiagramFactory> */
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'slug',
        'source_solution_id',
        'target_solution_id',
        'direction',
        'protocol',
        'sync_mode',
        'status',
        'criticality',
        'chain',
        'viz_layout',
    ];

    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            // `protocol` (the summary scalar — the first edge with a protocol,
            // see SyncDiagramFromChain) is a plain string, NOT cast to
            // `App\Enums\Protocol`: since the per-edge protocol accepts free
            // text, an Eloquent enum cast would throw a ValueError the moment
            // it tried to save a value outside the enum's cases. Resolve a
            // label the same way `ChainGraph::resolveProtocol()` does:
            // `Protocol::tryFrom($value)?->label() ?? $value`.
            'sync_mode'  => SyncMode::class,
            'status'     => DiagramStatus::class,
            'chain'      => 'array',
            'viz_layout' => 'array',
        ];
    }

    /** Label for the `criticality` value, now managed via `AttributeOption` (group shared with Solution). */
    protected function criticalityLabel(): Attribute
    {
        return Attribute::get(fn () => AttributeOption::labelFor('criticality', $this->criticality));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The F3 canvas rendered to a PNG, refreshed by the client every time the
     * layout is saved (`chain-viz.js`'s `save()` → `captureDiagramCanvas()`).
     *
     * A DERIVED artifact, never an input: the topology stays the `chain` and
     * the positions stay `viz_layout`. It exists so a CATI deck can show the
     * architecture without a browser in the loop — the deck embeds this image
     * and links back to the canvas, which keeps the canvas the one place a
     * diagram is edited. `singleFile()` because only the current picture is
     * ever wanted; each save replaces the last.
     */
    public const DIAGRAM_COLLECTION = 'diagram';

    public function registerMediaCollections(): void
    {
        // Images pasted onto the canvas (`ChainNodeKind::Image`). Must be the
        // `docs` name even though this model no longer carries documentation:
        // `MediaController::show()` serves `/files/{id}` for that collection
        // only, and an image node references its picture by exactly that URL.
        $this->addMediaCollection(Documentable::DOCS_COLLECTION);
        $this->addMediaCollection(self::DIAGRAM_COLLECTION)->singleFile();
    }

    /** The current rendered picture, if the canvas has ever been saved with one. */
    public function picture(): ?Media
    {
        return $this->getFirstMedia(self::DIAGRAM_COLLECTION);
    }

    /* ------------------------------------------------------------------ */
    /*  ChainCanvas — see App\Contracts\ChainCanvas */
    /* ------------------------------------------------------------------ */

    public function chainData(): ?array
    {
        return $this->chain;
    }

    public function vizLayout(): ?array
    {
        return $this->viz_layout;
    }

    public function writeChain(?array $chain = null, ?array $layout = null): void
    {
        $this->update(array_filter(
            ['chain' => $chain, 'viz_layout' => $layout],
            fn ($value) => $value !== null,
        ));
    }

    /**
     * A diagram's chain DRIVES its derived columns, so every write re-derives
     * them — that is what keeps the ecosystem map a reading of the drawings
     * rather than a second, hand-maintained truth. This is the whole
     * difference between the two `ChainCanvas` implementations: a submission's
     * diagram derives nothing.
     */
    public function afterChainMutation(): void
    {
        app(SyncDiagramFromChain::class)->handle($this);
    }

    public function chainImageCollection(): string
    {
        return Documentable::DOCS_COLLECTION;
    }

    public function chainDiagramCollection(): string
    {
        return self::DIAGRAM_COLLECTION;
    }

    /**
     * The canvas's endpoints. Flat under `/diagrams/{diagram}` — a diagram is
     * addressed by itself now, so unlike the old integration routes there is
     * no `{solution}` to scope against and no "which participant am I browsing
     * from?" question to answer before a URL can be built.
     *
     * @return array<string, string>
     */
    public function chainUrls(): array
    {
        return [
            'saveUrl'         => route('diagrams.layout.save', $this),
            'diagramUrl'      => route('diagrams.picture.store', $this),
            'nodeAddUrl'      => route('diagrams.chain.node.add', $this),
            'imageAddUrl'     => route('diagrams.chain.image.add', $this),
            'nodeUpdateUrl'   => route('diagrams.chain.node.update', [$this, 'NODE_INDEX']),
            'nodeRemoveUrl'   => route('diagrams.chain.node.remove', [$this, 'NODE_INDEX']),
            'edgeAddUrl'      => route('diagrams.chain.edge.add', $this),
            'edgeUpdateUrl'   => route('diagrams.chain.protocol.update', [$this, 'EDGE_INDEX']),
            'edgeRetargetUrl' => route('diagrams.chain.edge.retarget', [$this, 'EDGE_INDEX']),
            'edgeRemoveUrl'   => route('diagrams.chain.edge.remove', [$this, 'EDGE_INDEX']),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relations */
    /* ------------------------------------------------------------------ */

    /**
     * Documentation pages that point at this drawing — the authored side of
     * the diagram/solution link, and the reason the FK lives on the page: one
     * diagram legitimately explains several pages (often in several
     * solutions' trees), while a page never has two drawings to reconcile.
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Solution::class, 'source_solution_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Solution::class, 'target_solution_id');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Solution::class, 'diagram_solution')
            ->withPivot(['position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    /**
     * The diagrams index's search and filters. Kept as a scope, like
     * `Solution::scopeFilter()`, so the list, its result counter and any
     * mutation response that re-renders the list all read one definition.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where('name', 'like', '%' . $search . '%');
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        // Whether the drawing reaches the catalog at all: does it name a
        // Solution among its blocks? It used to filter on "some page points at
        // me", which stopped being a relation the database can answer — prose
        // cites a diagram in its text now.
        if (($filters['placed'] ?? null) === 'yes') {
            $query->whereHas('participants');
        } elseif (($filters['placed'] ?? null) === 'no') {
            $query->whereDoesntHave('participants');
        }
    }
}
