<?php

namespace App\Models;

// Uncomment to enforce email verification (e.g., for 2-step login flow):
// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Enums\AccessLevel;
use App\Enums\AccessModule;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class User extends Authenticatable implements HasMedia, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, InteractsWithMedia, Notifiable, SoftDeletes;

    /**
     * `entra_id` is deliberately absent: it is the account's identity at the
     * identity provider, written only by App\Actions\Auth\ResolveEntraUser
     * after Microsoft has attested it. Mass-assignable, it would be a posted
     * field that says "I am this person at Entra".
     */
    protected $fillable = ['role', 'access', 'name', 'email', 'password', 'preferences'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'access_token_expires_at' => 'datetime',
            'email_verified_at'       => 'datetime',
            'password'                => 'hashed',
            'preferences'             => 'array',
            'role'                    => UserRole::class,
            'access'                  => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    /**
     * This account's level in `$module`. An admin is an Editor everywhere, and
     * a module nobody set answers that module's `defaultLevel()`.
     */
    public function accessLevel(AccessModule $module): AccessLevel
    {
        if ($this->isAdmin()) {
            return AccessLevel::Editor;
        }

        return AccessLevel::tryFrom((string) ($this->access[$module->value] ?? '')) ?? $module->defaultLevel();
    }

    /** Opening `$module` at all — anything but `None`. */
    public function canView(AccessModule $module): bool
    {
        return $this->accessLevel($module)->canView();
    }

    /** Creating and changing records in `$module` — what its Editor level adds. */
    public function canEdit(AccessModule $module): bool
    {
        return $this->accessLevel($module) === AccessLevel::Editor;
    }

    /**
     * Sets one module's level. The module's default is stored as an absent
     * key, so the column only records what differs from it.
     */
    public function setAccessLevel(AccessModule $module, AccessLevel $level): void
    {
        $access = $this->access ?? [];

        if ($level === $module->defaultLevel()) {
            unset($access[$module->value]);
        } else {
            $access[$module->value] = $level->value;
        }

        $this->access = $access === [] ? null : $access;
    }

    /**
     * How long an access link stays usable. Seven days, and reusable within
     * them: the link is handed over by hand (Teams, a call, in person), so a
     * single-use one that the person opens on the wrong device is a support
     * request. It is safe to be generous because of what it opens — the
     * password screen, never a session (see `App\Actions\GrantPersonAccess`).
     */
    public const ACCESS_TOKEN_DAYS = 7;

    /**
     * The catalog record for this account's human, when somebody linked one.
     *
     * Nullable in both directions on purpose: most people in the catalog never
     * log in, and `admin@leomadeiras.com.br` is an account with no Person and
     * never will have one.
     */
    public function person(): HasOne
    {
        return $this->hasOne(Person::class);
    }

    /** Whether this account's access link can still be opened. */
    public function hasLiveAccessToken(): bool
    {
        return filled($this->access_token)
            && $this->access_token_expires_at !== null
            && $this->access_token_expires_at->isFuture();
    }

    public function flowspecChats(): HasMany
    {
        return $this->hasMany(FlowspecChat::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(120)
            ->height(120)
            ->nonQueued();
    }

    public function avatarUrl(): string
    {
        return $this->getFirstMediaUrl('avatar', 'thumb')
            ?: 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=0a0a0a&color=fff&size=120';
    }
}
