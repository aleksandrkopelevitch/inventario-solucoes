<?php

namespace App\Models;

use Database\Factories\ApprovedTopologyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The TO BE a committee approved, waiting for the catalog to catch up.
 *
 * This exists because Fase 3 reopened a hole Fase 4 had declared closed.
 * `PromoteApprovedSubmission` used to argue that promoting topology was
 * unnecessary — "the architect edits the inventory's own chain while
 * preparing, so the topology is already promoted the moment it is drawn". That
 * was true while the diagrams were pictures of the LIVE diagram canvas. It
 * stopped being true when a submission got drawings of its own, whose
 * `afterChainMutation()` is deliberately empty: an approved TO BE lived only
 * on the submission, and the catalog drifted again — the exact failure this
 * module exists to prevent.
 *
 * Why a record instead of writing the catalog at approval:
 *
 * - A submission's TO BE is a FREE GRAPH. It can describe several diagrams
 *   at once, or one that does not exist yet. Choosing which `Diagram` it
 *   becomes is a judgment call, and an approval that guesses would overwrite
 *   real topology with a guess.
 * - Silence is what caused the drift. A pending row is visible on the Solution
 *   and on the submission, and it can be closed two ways that mean different
 *   things: APPLIED ("the catalog now says this") and DISMISSED ("the catalog
 *   was already right").
 *
 * The chain is a SNAPSHOT taken at approval. Applying has to write what the
 * committee blessed, not whatever the drawing has become since.
 */
class ApprovedTopology extends Model
{
    /** @use HasFactory<ApprovedTopologyFactory> */
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'solution_id',
        'chain',
        'viz_layout',
        'approved_at',
        'diagram_id',
        'applied_at',
        'applied_by_id',
        'dismissed_at',
        'dismissed_by_id',
        'dismissed_reason',
    ];

    protected function casts(): array
    {
        return [
            'chain'        => 'array',
            'viz_layout'   => 'array',
            'approved_at'  => 'datetime',
            'applied_at'   => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function solution(): BelongsTo
    {
        return $this->belongsTo(Solution::class);
    }

    /** What it was applied to, when it was. */
    public function diagram(): BelongsTo
    {
        return $this->belongsTo(Diagram::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_id');
    }

    /** Neither applied nor dismissed — the catalog still owes this. */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('applied_at')->whereNull('dismissed_at');
    }

    public function isPending(): bool
    {
        return $this->applied_at === null && $this->dismissed_at === null;
    }

    /** How many blocks the approved drawing has, for a row that has to say something. */
    public function nodeCount(): int
    {
        return count($this->chain['nodes'] ?? []);
    }
}
