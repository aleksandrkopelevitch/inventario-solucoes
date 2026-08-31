<?php

namespace App\Models;

use Database\Factories\NotebookFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A Notebook ("Caderno") — a body of documentation, modelled on a GitBook
 * Space. It is the ONE container of `DocumentationPage`s, and the only one
 * there has ever needed to be.
 *
 * It replaces a polymorphic `container` that could be a `Solution` or a
 * standalone `DocumentationGroup`. Both halves of that were the same thing
 * wearing different clothes, and the Solution half carried a false claim: that
 * a body of documentation describes exactly one system. It usually doesn't —
 * which is what `solutions()` below is for. A notebook may link to **0..N**
 * solutions, and zero is a perfectly ordinary state (a cross-cutting process,
 * or a freshly imported GitBook space nobody has filed yet).
 *
 * What follows from "the notebook is the container" and is easy to forget:
 *
 * - **The magic link is the notebook's** (`public_token`), not a solution's.
 *   You share a caderno.
 * - **The AI context documents are the notebook's** (`CONTEXT_COLLECTION`).
 *   The Assiste IA chat is scoped to a notebook; the ATTRIBUTE half of its
 *   requirements checklist is what reaches back through `solutions()`.
 * - **A solution's documentation is derived, never owned** — it is the union
 *   of its linked notebooks' pages, which is why `Solution` has no `pages()`
 *   relation any more.
 */
class Notebook extends Model implements HasMedia
{
    /** @use HasFactory<NotebookFactory> */
    use HasFactory, InteractsWithMedia;

    /**
     * Context documents (PDF/image/text) attached to the notebook and reused by
     * the Documentation Assistant chat — they feed the LLM generation
     * (App\Services\Documentation\ContextDocumentResolver). Separate from the
     * `docs` collection (media embedded in a page's Markdown): these files never
     * appear in the text, they only go into the model's prompt.
     *
     * It lived on `Solution` until the container swap, and moved rather than
     * being duplicated: the chat is about a page, a page belongs to a notebook,
     * so the notebook is what can always answer "what context do I have?" —
     * including for the 38 imported spaces that link to no solution at all.
     */
    public const CONTEXT_COLLECTION = 'context_documents';

    /**
     * Characters in the secret code — the string a reader types to reveal one
     * protected value in this caderno's pages
     * (App\Support\Documentation\SecretText).
     *
     * Six, deliberately short, and it is short because a person is meant to
     * receive it over Teams and type it — the same reason `TOKEN_LENGTH` is 12
     * and this is not. Six alphanumerics is ~36 bits, far too little on its own,
     * so it is NOT the whole authorization the way the public token is: every
     * attempt goes through App\Actions\Documentation\RevealPageSecret, which
     * allows five per reader per twelve hours. Lengthen this and the throttle
     * still matters; drop the throttle and this length is the attack.
     */
    public const SECRET_CODE_LENGTH = 6;

    protected $fillable = [
        'name',
        'slug',
        'public_token',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function registerMediaCollections(): void
    {
        // Served by NotebookContextDocumentController — the `files.show`/
        // MediaController route only serves the `docs` collection.
        $this->addMediaCollection(self::CONTEXT_COLLECTION);
    }

    /** The page tree, flat and ordered — see `pages()` on the trap in `position`. */
    public function pages(): HasMany
    {
        return $this->hasMany(DocumentationPage::class)->orderBy('position');
    }

    /**
     * Only pages with actual content — used for coverage and for the flowSpec
     * context picker. A page with an empty body is a heading with nothing under
     * it wherever documentation is listed to be chosen from or counted.
     */
    public function documentedPages(): HasMany
    {
        return $this->pages()->whereNotNull('documentation')->where('documentation', '<>', '');
    }

    /**
     * The solutions this notebook documents.
     *
     * Deliberately unordered by anything but name and carrying no pivot data:
     * there is no "primary" solution, because the moment one exists every
     * consumer has to decide whether it is reading the primary or the set, and
     * the answer drifts. A notebook simply describes these systems.
     */
    public function solutions(): BelongsToMany
    {
        return $this->belongsToMany(Solution::class)->orderBy('name');
    }

    /** URL of the documentation's public link, or null if not shared. */
    public function publicDocsUrl(): ?string
    {
        return $this->public_token
            ? route('public.docs.notebook', $this->public_token)
            : null;
    }

    /**
     * Generates a fresh secret code and saves it. Every previously shared code
     * stops working, which is the point — it is the only answer to one having
     * been passed on to somebody it wasn't meant for.
     *
     * `Str::random()` (CSPRNG) rather than a friendlier alphabet: a code read
     * as `X6h2dG` is mixed case on purpose, and comparison is exact
     * (`hash_equals`), never folded — see the note in
     * `PublicDocumentationController::diagramPicture()` on why authorisation
     * is the one place this app does not fold case.
     */
    public function rotateSecretCode(): string
    {
        // Not in `$fillable`, like `parent_id` on a page: the code is written
        // here and by the migration that backfilled it, never from a payload.
        $this->secret_code = Str::random(self::SECRET_CODE_LENGTH);
        $this->save();

        return $this->secret_code;
    }

    /** Whether `$code` is this caderno's secret code. Exact match, timing-safe. */
    public function secretCodeMatches(?string $code): bool
    {
        return filled($this->secret_code)
            && filled($code)
            && hash_equals((string) $this->secret_code, (string) $code);
    }

    /**
     * No FK to cascade from the page side would clean up FILES — deletes each
     * page through its own model so Spatie's `deleting` hook runs and the
     * embedded media goes with it. The `cascadeOnDelete` on
     * `documentation_pages.notebook_id` is the safety net for a delete that
     * bypasses Eloquent; it just can't delete anything off disk.
     */
    protected static function booted(): void
    {
        // Every caderno has a secret code from the moment it exists. Generating
        // it lazily, the first time a page grows a `{% secret %}`, would mean
        // the admin panel has nothing to show until then — and the code is
        // something you hand out BEFORE it is needed, not after.
        static::creating(function (self $notebook) {
            $notebook->secret_code ??= Str::random(self::SECRET_CODE_LENGTH);
        });

        static::deleting(fn (self $notebook) => $notebook->pages()->get()->each->delete());
    }
}
