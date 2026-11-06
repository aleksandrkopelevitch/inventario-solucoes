<?php

namespace App\Models;

use App\Support\Digibee\Monitor\MonitorSession;
use Database\Factories\DigibeeMonitorSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Digibee web session the SERVER holds, sent from an admin's own browser by
 * the "Enviar sessão ao iSol" bookmarklet (`ImportDigibeeSession`).
 *
 * It is a person's whole Digibee role for ~24h — production deletes included —
 * which is why both headers are encrypted at rest, why nothing prints them, and
 * why `DigibeeMonitorClient` (its only reader) is a closed list of read routes.
 * The workstation keeps its own session in a 0600 file (`digibee:monitor:login`);
 * the two never mix.
 *
 * Rows are kept rather than overwritten: who sent it, and when, is the
 * record of whose identity the server has been reading Digibee with.
 */
class DigibeeMonitorSession extends Model
{
    /** @use HasFactory<DigibeeMonitorSessionFactory> */
    use HasFactory;

    protected $fillable = ['authorization', 'apikey', 'expires_at'];

    protected $hidden = ['authorization', 'apikey'];

    protected function casts(): array
    {
        return [
            'authorization' => 'encrypted',
            'apikey'        => 'encrypted',
            'expires_at'    => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Captures whose token has not run out (with the same minute of margin MonitorSession keeps). */
    public function scopeUnexpired(Builder $query): void
    {
        $query->where('expires_at', '>', now()->addMinute());
    }

    /** The newest capture, expired or not — what the screen describes. */
    public static function latestCapture(): ?self
    {
        return static::query()->with('user:id,name')->latest('id')->first();
    }

    /** The session the server reads Digibee with, if one is still valid. */
    public static function current(): ?self
    {
        return static::query()->unexpired()->latest('id')->first();
    }

    /** Stores a session, dated by the token's own `exp`. */
    public static function capture(MonitorSession $session, ?User $user): self
    {
        $headers = $session->headers();

        $row = new self([
            'authorization' => $headers['authorization'],
            'apikey'        => $headers['apikey'],
            'expires_at'    => $session->expiresAt(),
        ]);
        $row->user()->associate($user);
        $row->save();

        return $row;
    }

    public function toMonitorSession(): MonitorSession
    {
        return new MonitorSession($this->authorization, $this->apikey);
    }

    public function expired(): bool
    {
        return $this->expires_at === null || $this->expires_at->subMinute()->isPast();
    }
}
