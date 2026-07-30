<?php

namespace App\Models;

use Database\Factories\FlowspecGuidelineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin-curated guideline document (F8) — Markdown notes always folded
 * into FlowspecPromptBuilder::systemPrompt() for the Especialista em
 * Integrações, unlike FlowspecExample (selected per-request by tags).
 */
class FlowspecGuideline extends Model
{
    /** @use HasFactory<FlowspecGuidelineFactory> */
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

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
