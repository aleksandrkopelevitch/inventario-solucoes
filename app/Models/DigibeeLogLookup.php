<?php

namespace App\Models;

use App\Mcp\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One read of the Digibee logs made through iSol, and who made it. Every such
 * read reaches Digibee as the account whose session the server holds, so the
 * platform's audit names that one person for everybody; this row names who
 * actually asked. Kept as long as the logs themselves.
 */
class DigibeeLogLookup extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $fillable = ['source', 'action', 'environment', 'pipeline', 'term', 'execution_key'];

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

    /** @param  array{environment?: ?string, pipeline?: ?string, term?: ?string, execution_key?: ?string}  $what */
    public static function record(string $source, string $action, array $what, ?User $user, ?McpToken $token = null): void
    {
        $lookup = new self(['source' => $source, 'action' => $action] + $what);
        $lookup->user()->associate($user);
        $lookup->mcpToken()->associate($token);
        $lookup->save();
    }

    /** @param  array{environment?: ?string, pipeline?: ?string, term?: ?string, execution_key?: ?string}  $what */
    public static function recordFor(Actor $actor, string $action, array $what): void
    {
        self::record('mcp', $action, $what, $actor->user, $actor->token);
    }

    public function askedBy(): string
    {
        return $this->user?->name ?? ($this->mcpToken ? 'token “' . $this->mcpToken->name . '”' : 'conta removida');
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(DigibeeLogReport::RETENTION_DAYS));
    }
}
