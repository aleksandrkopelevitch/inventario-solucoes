<?php

namespace App\Models;

use Database\Factories\McpTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A bearer token for the MCP server — the one credential `POST /mcp` accepts.
 *
 * Deliberately NOT a `User`, and the distinction is the whole security model of
 * the feature. An account is a person: it has a role, a password, a session, and
 * `people.user_id` may point at it. A token is a key handed to a PROGRAM —
 * Claude, ChatGPT, Gemini — that an admin mints, names after what will hold it,
 * and destroys when that thing goes away. Modelling it as a `User` would mean a
 * fifth role nobody can log in as, and `Auth::user()` returning something that
 * is not a human in the half of the app that assumes it is.
 *
 * What a token may read is therefore not a role either: it is fixed, and it is
 * the narrowest reading of the catalog the app has (see `App\Mcp\ToolRegistry`).
 * Documentation is the PUBLISHED cadernos and nothing else — the same rule
 * `/docs` answers to (`Notebook::scopePublished()`), for the same reason stated
 * a different way: a token is a URL-shaped secret that ends up in somebody
 * else's configuration file, so what it reaches has to be what an admin
 * deliberately decided the whole company may read. An unpublished caderno is
 * invisible to it exactly as it is to an admin browsing `/docs`.
 *
 * **The plaintext exists for one response.** `mint()` returns it, the admin
 * screen prints it once, and after that only `last_four` survives — so a lost
 * token is replaced, never recovered. That is why there is no `revoked_at`
 * column: deleting the row IS the revocation, immediate and total, and a token
 * nobody can read again has no state worth keeping around.
 */
class McpToken extends Model
{
    /** @use HasFactory<McpTokenFactory> */
    use HasFactory;

    /**
     * The scheme every token carries. It is a prefix rather than decoration:
     * secret scanners (GitHub's included) key off exactly this kind of fixed
     * marker, and a token committed by accident is worth catching by shape.
     */
    public const PREFIX = 'isol_mcp_';

    /** Characters of CSPRNG after the prefix — ~238 bits. */
    public const RANDOM_LENGTH = 40;

    /**
     * `token_hash` and `last_four` are absent on purpose: both are derived from
     * a plaintext only `mint()` ever holds, so a payload must never be able to
     * name either. `created_by_user_id` is written through the relation.
     */
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Mints a token, returning the row and the plaintext — the only moment the
     * plaintext exists.
     *
     * @return array{token: self, plain: string}
     */
    public static function mint(string $name, ?User $creator = null): array
    {
        $plain = self::PREFIX . Str::random(self::RANDOM_LENGTH);

        $token = new self(['name' => $name]);
        $token->token_hash = self::hash($plain);
        $token->last_four = substr($plain, -4);
        $token->createdBy()->associate($creator);
        $token->save();

        return ['token' => $token, 'plain' => $plain];
    }

    /**
     * sha256, NOT bcrypt/argon.
     *
     * A password is hashed slowly because it is short, human-chosen and
     * guessable; the cost is what stands between a leaked table and the
     * account. This is 40 characters of CSPRNG, so there is nothing to guess —
     * and a slow hash could not be an index, which would turn every MCP request
     * into a full scan comparing row by row. Deterministic hashing is what lets
     * the lookup be a single indexed `where`.
     */
    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * The token matching this plaintext, or null.
     *
     * Wrong-shaped input is refused before the query rather than after: a
     * bearer value that is not one of ours cannot match anything, and not
     * asking the database keeps a stream of junk `Authorization` headers from
     * being a stream of queries.
     */
    public static function findByPlaintext(?string $plain): ?self
    {
        if (blank($plain) || ! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        return self::query()->where('token_hash', self::hash($plain))->first();
    }

    /**
     * Records that the token answered a request.
     *
     * Throttled to once a minute, and that is not a micro-optimisation: an MCP
     * client sends `tools/list` and then a call per turn, so an unthrottled
     * touch makes every read of the catalog a write to this table. What the
     * column is for — "is this token still in use, and may I delete it?" — is
     * answered exactly as well by a minute-old timestamp.
     */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at?->gt(now()->subMinute())) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    /** How the token is named on screen, since the plaintext is gone. */
    public function masked(): string
    {
        return self::PREFIX . '…' . $this->last_four;
    }
}
