<?php

namespace App\Models;

use App\Enums\HealingVerdict;
use App\Enums\PipelineRunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the Digibee lifecycle, watched from the browser.
 *
 * `rounds` is appended DURING the run, not written at the end: the screen
 * exists to show progress cycle by cycle, and each cycle is a real deployment
 * in the realm — a person watching deserves to see them happen rather than a
 * spinner that resolves minutes later.
 */
class PipelineRun extends Model
{
    /** @use HasFactory<\Database\Factories\PipelineRunFactory> */
    use HasFactory;

    /**
     * How long a run may sit untouched before it is presumed dead.
     *
     * A worker killed mid-job (a `composer dev` restart is enough) leaves the
     * row `running` forever, and nothing else would ever move it: the job that
     * would have finished it no longer exists. So staleness is checked when a
     * new run is requested and on every poll, which is the same reaping rule
     * the flowSpec chat needed for exactly the same reason.
     *
     * Generous, because a round is a real deploy plus a battery: the healing
     * loop's own ceiling is minutes per round.
     */
    public const STALE_AFTER_SECONDS = 1800;

    protected $fillable = [
        'flowspec_message_id',
        'user_id',
        'pipeline_name',
        'environment',
        'creates',
        'trigger_kind',
        'trigger_cron',
        'trigger_event',
        'status',
        'verdict',
        'rounds',
        'readiness',
        'endpoint',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'creates'     => 'boolean',
            'status'      => PipelineRunStatus::class,
            'verdict'     => HealingVerdict::class,
            'rounds'      => 'array',
            'readiness'   => 'array',
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(FlowspecMessage::class, 'flowspec_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Runs still expected to move on their own. */
    public function scopeUnsettled(Builder $query): void
    {
        $query->whereIn('status', [PipelineRunStatus::Pending->value, PipelineRunStatus::Running->value]);
    }

    /**
     * Whether this run has gone quiet for longer than a worker could
     * plausibly be holding it.
     *
     * Measured from `updated_at` rather than `created_at`: a run appending
     * rounds is alive however long it has been going, and judging it by its
     * start would reap a healthy loop mid-flight.
     */
    public function stale(): bool
    {
        return ! $this->status->settled()
            && $this->updated_at !== null
            && $this->updated_at->diffInSeconds(now()) > self::STALE_AFTER_SECONDS;
    }

    /** @return list<array<string, mixed>> */
    public function roundList(): array
    {
        return is_array($this->rounds) ? array_values($this->rounds) : [];
    }
}
