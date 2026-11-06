<?php

namespace App\Models;

use App\Enums\DigibeeLogReportStatus;
use App\Support\Digibee\Monitor\LogQuery;
use Carbon\CarbonImmutable;
use Database\Factories\DigibeeLogReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A report collected from the Digibee logs on the iSol server — every
 * execution a search matched, opened and read top to bottom.
 *
 * The row is the question and its outcome; the executions are a JSON file on
 * the private disk (`file()`), REDACTED before it was written, since a report
 * is read on screen, downloaded and quoted into chats. Readable by every
 * account that reads the Integrations module, like the Especialista's
 * conversations; addressed by `uuid` everywhere outside the database.
 *
 * Pruned ten days after it was made — the platform keeps production logs for
 * ten days, and a report outliving its source is a copy nobody can check.
 */
class DigibeeLogReport extends Model
{
    /** @use HasFactory<DigibeeLogReportFactory> */
    use HasFactory;

    use Prunable;

    /** A report still in flight this long after its last update is a worker that died. */
    public const STALE_AFTER_MINUTES = 15;

    public const RETENTION_DAYS = 10;

    protected $fillable = [
        'source', 'status', 'environment', 'pipeline', 'term', 'where', 'from_at', 'to_at',
        'executions_found', 'executions_opened', 'error',
    ];

    protected function casts(): array
    {
        return [
            'status'  => DigibeeLogReportStatus::class,
            'from_at' => 'immutable_datetime',
            'to_at'   => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $report) => $report->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<McpToken, $this> */
    public function mcpToken(): BelongsTo
    {
        return $this->belongsTo(McpToken::class);
    }

    /** A new report for `$query`, asked by a person or a program. */
    public static function begin(LogQuery $query, string $source, DigibeeLogReportStatus $status, ?User $user, ?McpToken $token = null): self
    {
        $report = new self([
            'source'      => $source,
            'status'      => $status,
            'environment' => $query->environment,
            'pipeline'    => $query->pipeline,
            'term'        => $query->term,
            'where'       => $query->where,
            'from_at'     => $query->from,
            'to_at'       => $query->to,
        ]);
        $report->user()->associate($user);
        $report->mcpToken()->associate($token);
        $report->save();

        return $report;
    }

    /** The question again, with the same two instants it was asked with. */
    public function logQuery(): LogQuery
    {
        return new LogQuery(
            $this->environment,
            CarbonImmutable::parse($this->from_at),
            CarbonImmutable::parse($this->to_at),
            $this->pipeline,
            $this->term,
            $this->where,
        );
    }

    /** Pending or running and still alive — a dead worker's report does not count. */
    public function scopeInFlight(Builder $query): void
    {
        $query->whereIn('status', [DigibeeLogReportStatus::Pending, DigibeeLogReportStatus::Running])
            ->where('updated_at', '>', now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /** Path on the private (`local`) disk. */
    public function file(): string
    {
        return 'digibee-logs/reports/' . $this->uuid . '.json';
    }

    /** Stores the already-redacted report and settles the row. */
    public function complete(string $redactedJson, int $found, int $opened): void
    {
        Storage::disk('local')->put($this->file(), $redactedJson);

        $this->update([
            'status'            => DigibeeLogReportStatus::Done,
            'executions_found'  => $found,
            'executions_opened' => $opened,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function contents(): ?array
    {
        $json = Storage::disk('local')->get($this->file());

        return $json === null ? null : (array) json_decode($json, true);
    }

    /** Who asked, for a list line. */
    public function askedBy(): string
    {
        return $this->user?->name ?? ($this->mcpToken ? 'token “' . $this->mcpToken->name . '”' : 'conta removida');
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    protected function pruning(): void
    {
        Storage::disk('local')->delete($this->file());
    }
}
