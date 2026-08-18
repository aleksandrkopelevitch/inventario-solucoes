<?php

namespace App\Models;

use Database\Factories\CatiGuidelineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin-curated committee guideline — Markdown always folded into the
 * interview's system prompt, mirroring FlowspecGuideline. Unlike CatiExample
 * (selected per request by tags), every active guideline goes into every turn.
 */
class CatiGuideline extends Model
{
    /** @use HasFactory<CatiGuidelineFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'source',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
