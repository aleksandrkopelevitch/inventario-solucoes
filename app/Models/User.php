<?php

namespace App\Models;

// Uncomment to enforce email verification (e.g., for 2-step login flow):
// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class User extends Authenticatable implements HasMedia
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithMedia, Notifiable, SoftDeletes;

    protected $fillable = ['role', 'name', 'email', 'password', 'preferences'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'access_token_expires_at' => 'datetime',
            'email_verified_at'       => 'datetime',
            'password'                => 'hashed',
            'preferences'             => 'array',
            'role'                    => UserRole::class,
        ];
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
