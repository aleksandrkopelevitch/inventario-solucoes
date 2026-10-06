<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The magic link of a screen that has no record of its own to carry a token —
 * today only the solutions spreadsheet (`self::SOLUTIONS_SPREADSHEET`).
 *
 * Same contract as `Notebook::public_token`: an opaque token in the URL is the
 * WHOLE authorization (no auth middleware, no throttle), so its length is a
 * security property — see `NotebookController::share()`. Generating is
 * idempotent, revoking deletes the row and the old link stops resolving.
 */
class PublicLink extends Model
{
    public const SOLUTIONS_SPREADSHEET = 'solutions-spreadsheet';

    /** Characters in a freshly generated token — same floor as a caderno's. */
    public const TOKEN_LENGTH = 12;

    protected $fillable = [
        'subject',
        'token',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function for(string $subject): ?self
    {
        return static::where('subject', $subject)->first();
    }

    /** The existing link for `$subject`, or a new one — never a second token. */
    public static function generate(string $subject, ?User $by = null): self
    {
        return static::firstOrCreate(
            ['subject' => $subject],
            ['token' => Str::random(self::TOKEN_LENGTH), 'created_by' => $by?->id],
        );
    }

    public static function revoke(string $subject): void
    {
        static::where('subject', $subject)->delete();
    }

    /**
     * Compared as bytes, never folded: this is authorization, and a folded
     * match would let `ABC…` stand in for `abc…`.
     */
    public static function resolve(string $subject, string $token): self
    {
        return static::where('subject', $subject)->where('token', $token)->firstOrFail();
    }
}
