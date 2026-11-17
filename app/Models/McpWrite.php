<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One change an MCP write tool made, kept so an admin can read what a chat did
 * and revert it ("Alterações via MCP", App\Actions\Mcp\RevertMcpWrite).
 *
 * `before` / `after` hold only the FIELDS this write changed — `before` is null
 * on a create. A relation counts as one field holding its whole set
 * (`{"people": [{"person": "joao", "role": "technical"}]}` on the solution a
 * link was made on), so reverting a link is the same operation as reverting a
 * column: put the old value back.
 *
 * Both are encrypted at rest. A documentation page's body carries its
 * protected values (`{% secret %}`) in clear, and a log of its before/after
 * would otherwise be the one place those values sit unprotected in the
 * database. A key rotation keeps old rows readable through
 * `APP_PREVIOUS_KEYS`.
 *
 * Kept 180 days: a wrong edit is noticed in days or weeks, and half a year
 * covers a quarter's review with margin without letting the table grow
 * forever.
 */
class McpWrite extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    public const RETENTION_DAYS = 180;

    protected $fillable = [
        'user_id',
        'oauth_client_id',
        'tool',
        'subject_type',
        'subject_id',
        'before',
        'after',
        'reverted_at',
        'reverted_by_id',
    ];

    protected function casts(): array
    {
        return [
            'before'      => 'encrypted:array',
            'after'       => 'encrypted:array',
            'reverted_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function revertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reverted_by_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isCreate(): bool
    {
        return $this->before === null;
    }

    public function isReverted(): bool
    {
        return $this->reverted_at !== null;
    }

    /** Filters for the admin screen: person, connector and period. */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['user'] ?? null, fn (Builder $q, $user) => $q->where('user_id', $user))
            ->when($filters['client'] ?? null, fn (Builder $q, $client) => $q->where('oauth_client_id', $client))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where('created_at', '>=', $from . ' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->where('created_at', '<=', $to . ' 23:59:59'));
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
