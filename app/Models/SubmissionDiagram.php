<?php

namespace App\Models;

use App\Contracts\ChainCanvas;
use App\Contracts\Documentable;
use App\Enums\SubmissionDiagramKind;
use Database\Factories\SubmissionDiagramFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One of the four drawings behind a submission
 * (`App\Enums\SubmissionDiagramKind`).
 *
 * Two of them (AS IS, TO BE) are drawn on the same F3 canvas the diagrams
 * use, which is why this implements `ChainCanvas`: the canvas takes its
 * endpoints from the graph payload, so it never learns it is editing
 * something other than a Diagram. The other two (C4 C1/C2) hold an
 * uploaded picture and no chain at all.
 *
 * **Nothing is ever derived from this chain.** An Diagram's chain drives
 * participants, source/target, direction and protocol; a submission's drives
 * nothing, because a proposal is a thing being argued about, not a record of
 * what exists. A rejected proposal writing into the catalog is precisely the
 * drift this module exists to remove — so `afterChainMutation()` is empty on
 * purpose, and it is empty in one visible place rather than by omission.
 */
class SubmissionDiagram extends Model implements ChainCanvas, HasMedia
{
    /** @use HasFactory<SubmissionDiagramFactory> */
    use HasFactory, InteractsWithMedia;

    /** The uploaded picture of a C4 kind, and nothing else. `singleFile()`. */
    public const UPLOAD_COLLECTION = 'submission_diagram';

    /** The canvas's rendered PNG, republished on every layout save. `singleFile()`. */
    public const DIAGRAM_COLLECTION = 'diagram';

    protected $fillable = [
        'submission_id',
        'kind',
        'chain',
        'viz_layout',
    ];

    protected function casts(): array
    {
        return [
            'kind'       => SubmissionDiagramKind::class,
            'chain'      => 'array',
            'viz_layout' => 'array',
        ];
    }

    public function registerMediaCollections(): void
    {
        // Must be the `docs` name: MediaController::show() serves /files/{id}
        // only for that collection, and a pasted image node references its
        // picture by exactly that URL.
        $this->addMediaCollection(Documentable::DOCS_COLLECTION);
        $this->addMediaCollection(self::UPLOAD_COLLECTION)->singleFile();
        $this->addMediaCollection(self::DIAGRAM_COLLECTION)->singleFile();
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * The picture that represents this diagram on a slide, whichever way it
     * got here: the canvas's own capture for a drawn kind, the upload for a
     * C4 one.
     */
    public function picture(): ?Media
    {
        return $this->kind->isDrawn()
            ? $this->getFirstMedia(self::DIAGRAM_COLLECTION)
            : $this->getFirstMedia(self::UPLOAD_COLLECTION);
    }

    /** Whether this slot has anything to show — what the checklist reads. */
    public function isFilled(): bool
    {
        return $this->kind->isDrawn()
            // A chain with only the root node is an empty canvas, not a
            // drawing: `SubmissionDiagram::open()` seeds that root, so
            // counting it as filled would tick the committee's checklist for
            // every submission that ever opened the tab.
            ? count($this->chain['nodes'] ?? []) > 1
            : $this->getFirstMedia(self::UPLOAD_COLLECTION) !== null;
    }

    /* ------------------------------------------------------------------ */
    /*  ChainCanvas */
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

    /** Nothing is derived from a proposal's topology — see the class docblock. */
    public function afterChainMutation(): void {}

    public function chainImageCollection(): string
    {
        return Documentable::DOCS_COLLECTION;
    }

    public function chainDiagramCollection(): string
    {
        return self::DIAGRAM_COLLECTION;
    }

    /** @return array<string, string> */
    public function chainUrls(): array
    {
        // The MODEL, not `submission_id`: Submission's route key is its slug,
        // so an id here builds `/submissions/1/...` — which binds nothing and
        // 404s every call the canvas makes. Nothing else would have noticed:
        // the endpoints work fine when a test names the route itself, and the
        // only place the wrong URL exists is inside the payload the client
        // reads.
        $this->loadMissing('submission');

        $self = [$this->submission, $this];

        return [
            'saveUrl'         => route('submissions.diagrams.layout.save', $self),
            'diagramUrl'      => route('submissions.diagrams.picture.store', $self),
            'nodeAddUrl'      => route('submissions.diagrams.chain.node.add', $self),
            'imageAddUrl'     => route('submissions.diagrams.chain.image.add', $self),
            'nodeUpdateUrl'   => route('submissions.diagrams.chain.node.update', [...$self, 'NODE_INDEX']),
            'nodeRemoveUrl'   => route('submissions.diagrams.chain.node.remove', [...$self, 'NODE_INDEX']),
            'edgeAddUrl'      => route('submissions.diagrams.chain.edge.add', $self),
            'edgeUpdateUrl'   => route('submissions.diagrams.chain.protocol.update', [...$self, 'EDGE_INDEX']),
            'edgeRetargetUrl' => route('submissions.diagrams.chain.edge.retarget', [...$self, 'EDGE_INDEX']),
            'edgeRemoveUrl'   => route('submissions.diagrams.chain.edge.remove', [...$self, 'EDGE_INDEX']),
        ];
    }
}
