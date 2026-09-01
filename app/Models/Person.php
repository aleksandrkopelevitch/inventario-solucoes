<?php

namespace App\Models;

use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    /**
     * Static segments that sit where `people/{person}` does, so a person slugged
     * with one would be permanently unreachable at their own URL.
     *
     * `new` was already exposed before `accounts` joined it — a person actually
     * named "New" would have taken the create route's place — which is why this
     * list exists now rather than one segment later. Check it against
     * `php artisan route:list --path=people` when adding a segment.
     */
    public const RESERVED_SLUGS = ['new', 'accounts'];

    protected $table = 'people';

    protected $fillable = [
        'name',
        'slug',
        'company_id',
        'job_title',
        'email',
        'phone',
        'photo_path',
        'notes',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * A URL-safe slug for `$name` that no other person holds.
     *
     * On the model rather than in `PersonController` because there are two
     * places a person is born now: the catalog form, and an INVITE — inviting
     * somebody creates their catalog row, so the slug rules (and the reserved
     * segments above) have to be the same from both doors.
     */
    public static function uniqueSlug(string $name, ?self $except = null): string
    {
        $base = Str::slug($name) ?: 'pessoa';
        $slug = $base;
        $suffix = 1;

        while (in_array($slug, self::RESERVED_SLUGS, true) || self::where('slug', $slug)
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }

    /**
     * The person whose e-mail IS this one, however it was capitalised.
     *
     * Folded EQUALITY (`whereFoldedIs`), never the containment `whereFolded`
     * every search uses: this answers "which human is this", so matching
     * `admin@leo…` inside `outro-admin@leo…` would be the wrong person, not a
     * generous result.
     *
     * And it fails CLOSED on a blank e-mail. The folding macros treat an empty
     * value as "no constraint", which is right for a search box and dangerous
     * here: `withEmail(null)->first()` would otherwise hand back an arbitrary
     * person for an invite to attach an account to. 105 of 108 catalog rows have
     * no e-mail, so "nobody" is the only honest answer to "who is filed under
     * none".
     */
    public function scopeWithEmail(Builder $query, ?string $email): void
    {
        if (blank($email)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereFoldedIs('email', $email);
    }

    /**
     * The account this person logs in with, if they have one.
     *
     * `user_id` is deliberately absent from `$fillable` — like `parent_id` on a
     * documentation page, the link is written through the relation
     * (`user()->associate()`) by `GrantPersonAccess`/`LinkPersonAccount` and
     * never mass-assigned from a form. Granting access creates an ACCOUNT; that
     * must not be reachable by posting a `user_id` to the person's edit panel,
     * which an editor can do and an editor may not hand out access.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function solutions(): BelongsToMany
    {
        return $this->belongsToMany(Solution::class)
            ->withPivot(['role', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * People catalog filters — search by name/company/system and filters by
     * company and role. Reused by People\Index (list), People\ResultsCount
     * (counter) and People\FilterChips, so the three never diverge.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(fn (Builder $w) => $w
                ->whereFolded('name', $search)
                ->orWhereHas('company', fn (Builder $c) => $c->whereFolded('name', $search))
                ->orWhereHas('solutions', fn (Builder $s) => $s->whereFolded('name', $search))))
            ->when($filters['company'] ?? null, fn (Builder $q, $v) => $q->where('company_id', $v))
            // `wherePivot()` only exists on the BelongsToMany relation itself, not on
            // the plain Builder a whereHas() closure receives — calling it here silently
            // resolved to Eloquent's dynamic-where magic method instead (`where('pivot', $v)`,
            // a literal column that doesn't exist), so this filter matched nothing, ever.
            // Reference the pivot table's real column name directly.
            ->when($filters['role'] ?? null, fn (Builder $q, $v) => $q->whereHas('solutions', fn (Builder $s) => $s->where('person_solution.role', $v)));
    }
}
