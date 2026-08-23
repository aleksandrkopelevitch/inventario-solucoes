<?php

namespace App\Models;

use App\Actions\SyncIntegrationFromChain;
use App\Contracts\ChainCanvas;
use App\Contracts\Documentable;
use App\Enums\Direction;
use App\Enums\IntegrationStatus;
use App\Enums\SyncMode;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Integration extends Model implements ChainCanvas, Documentable
{
    /** @use HasFactory<IntegrationFactory> */
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
        'documentation',
    ];

    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            // `protocol` (the summary scalar — the first edge with a protocol,
            // see SyncIntegrationFromChain) is a plain string, NOT cast to
            // `App\Enums\Protocol`: since the per-edge protocol accepts free
            // text, an Eloquent enum cast would throw a ValueError the moment
            // it tried to save a value outside the enum's cases. Resolve a
            // label the same way `IntegrationsMap::resolveProtocol()` does:
            // `Protocol::tryFrom($value)?->label() ?? $value`.
            'sync_mode'  => SyncMode::class,
            'status'     => IntegrationStatus::class,
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
     * layout is saved (`integration-viz.js`'s `save()` → `captureDiagramCanvas()`).
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
        // See Solution::registerMediaCollections() — documentation media,
        // served by `files.show`, referenced as /files/{id} in the Markdown.
        $this->addMediaCollection(self::DOCS_COLLECTION);
        $this->addMediaCollection(self::DIAGRAM_COLLECTION)->singleFile();
    }

    /** The current rendered diagram, if the canvas has ever been saved with one. */
    public function diagram(): ?Media
    {
        return $this->getFirstMedia(self::DIAGRAM_COLLECTION);
    }

    /* ------------------------------------------------------------------ */
    /*  ChainCanvas — see App\Contracts\ChainCanvas */
    /* ------------------------------------------------------------------ */

    /**
     * Which participating Solution the canvas's URLs are built against.
     *
     * The chain routes are scope-bound (`{integration}` 404s unless
     * `{solution}` participates), so they need one — any participant
     * satisfies the binding, but the URLs should name the solution the person
     * is actually browsing, or "voltar" lands somewhere they never were.
     */
    private ?Solution $urlContextSolution = null;

    public function withSolutionContext(Solution $solution): static
    {
        $this->urlContextSolution = $solution;

        return $this;
    }

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
     * An integration's chain DRIVES its derived columns, so every write
     * re-derives them. This is the whole difference between the two
     * `ChainCanvas` implementations — a submission's diagram derives nothing.
     */
    public function afterChainMutation(): void
    {
        app(SyncIntegrationFromChain::class)->handle($this);
    }

    public function chainImageCollection(): string
    {
        return self::DOCS_COLLECTION;
    }

    public function chainDiagramCollection(): string
    {
        return self::DIAGRAM_COLLECTION;
    }

    /** @return array<string, string> */
    public function chainUrls(): array
    {
        $solution = $this->urlContextSolution
            ?? $this->source
            ?? $this->participants()->first();

        $self = [$solution, $this];

        return [
            'saveUrl'         => route('solutions.integrations.layout.save', $self),
            'diagramUrl'      => route('solutions.integrations.diagram.store', $self),
            'nodeAddUrl'      => route('solutions.integrations.chain.node.add', $self),
            'imageAddUrl'     => route('solutions.integrations.chain.image.add', $self),
            'nodeUpdateUrl'   => route('solutions.integrations.chain.node.update', [...$self, 'NODE_INDEX']),
            'nodeRemoveUrl'   => route('solutions.integrations.chain.node.remove', [...$self, 'NODE_INDEX']),
            'edgeAddUrl'      => route('solutions.integrations.chain.edge.add', $self),
            'edgeUpdateUrl'   => route('solutions.integrations.chain.protocol.update', [...$self, 'EDGE_INDEX']),
            'edgeRetargetUrl' => route('solutions.integrations.chain.edge.retarget', [...$self, 'EDGE_INDEX']),
            'edgeRemoveUrl'   => route('solutions.integrations.chain.edge.remove', [...$self, 'EDGE_INDEX']),
        ];
    }

    public function documentationTitle(): string
    {
        return $this->name;
    }

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
        return $this->belongsToMany(Solution::class, 'integration_solution')
            ->withPivot(['position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }
}
