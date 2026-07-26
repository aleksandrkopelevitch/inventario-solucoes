<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A documentation generation request from "Assiste IA": the UI dispatches the
 * GenerateDocumentationDraft job and polls `status` until `completed`/`failed`.
 * The `result` (Markdown) is loaded into the editor for review — nothing is
 * written to the page until the user saves. `target` is the DocumentationPage
 * (or Integration) being generated; `solution` owns the context documents.
 */
class DocumentationAiGeneration extends Model
{
    /**
     * Audit note stored in `error` when an orphaned `pending` generation is
     * reaped (worker died mid-job). Never shown to the user — the status
     * endpoint returns a generic message; this stays on the record for
     * auditing only, so it's internal text and stays in English.
     */
    public const INTERRUPTED_ERROR = 'Generation interrupted before completion — worker killed/restarted mid-job.';

    protected $fillable = [
        'target_type',
        'target_id',
        'solution_id',
        'user_id',
        'status',
        'prompt',
        'context_media_ids',
        'existing_content',
        'result',
        'error',
        'meta',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'context_media_ids' => 'array',
            'meta'              => 'array',
            'consumed_at'       => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Pending, but older than the job's max lifetime — the worker died mid-job
     * (killed/restarted) without running handle()/failed(). Left as-is it would
     * stay `pending` forever and block every future draft for its target.
     */
    public function isStale(): bool
    {
        return $this->isPending()
            && $this->created_at !== null
            && $this->created_at->lt(now()->subSeconds((int) config('services.documentation_ai.stale_after')));
    }

    /** Orphaned `pending` generations — see `config('services.documentation_ai.stale_after')`. */
    public function scopeStale(Builder $query): void
    {
        $query->where('status', 'pending')
            ->where('created_at', '<', now()->subSeconds((int) config('services.documentation_ai.stale_after')));
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function solution(): BelongsTo
    {
        return $this->belongsTo(Solution::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
