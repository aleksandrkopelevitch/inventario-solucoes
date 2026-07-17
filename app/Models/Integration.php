<?php

namespace App\Models;

use App\Contracts\Documentable;
use App\Enums\Direction;
use App\Enums\IntegrationStatus;
use App\Enums\Protocol;
use App\Enums\SyncMode;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\InteractsWithMedia;

class Integration extends Model implements Documentable
{
    /** @use HasFactory<IntegrationFactory> */
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'slug',
        'source_solution_id',
        'target_solution_id',
        'direction',
        'protocol',
        'sync_mode',
        'status',
        'criticality',
        'chain',
        'viz_layout',
        'documentation',
    ];

    protected function casts(): array
    {
        return [
            'direction'             => Direction::class,
            'protocol'              => Protocol::class,
            'sync_mode'             => SyncMode::class,
            'status'                => IntegrationStatus::class,
            'chain'                 => 'array',
            'viz_layout'            => 'array',
            'generated_flowspec'    => 'array',
            'flowspec_generated_at' => 'datetime',
        ];
    }

    /** Label for the `criticality` value, now managed via `AttributeOption` (group shared with Solution). */
    protected function criticalityLabel(): Attribute
    {
        return Attribute::get(fn () => AttributeOption::labelFor('criticality', $this->criticality));
    }

    /** Label for `flowspec_status` — only 'generated'/'validated' have a label; 'idle' (never attached) doesn't appear in the UI. */
    protected function flowspecStatusLabel(): Attribute
    {
        return Attribute::get(fn () => match ($this->flowspec_status) {
            'validated' => 'flowSpec validado',
            'generated' => 'flowSpec gerado',
            default     => null,
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function registerMediaCollections(): void
    {
        // See Solution::registerMediaCollections() — documentation media,
        // served by `files.show`, referenced as /files/{id} in the Markdown.
        $this->addMediaCollection(self::DOCS_COLLECTION);
    }

    public function documentationTitle(): string
    {
        return $this->name;
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Solution::class, 'source_solution_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Solution::class, 'target_solution_id');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Solution::class, 'integration_solution')
            ->withPivot(['position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }
}
