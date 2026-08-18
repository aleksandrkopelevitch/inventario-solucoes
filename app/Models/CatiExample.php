<?php

namespace App\Models;

use App\Enums\SubmissionSectionKey;
use Database\Factories\CatiExampleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A past approved submission kept as a few-shot example, selected per request
 * by tags — mirroring FlowspecExample, including the reason to keep only two
 * or three per prompt: more dilutes the signal instead of adding to it.
 *
 * `sections` is keyed by SubmissionSectionKey. It is harvested rather than
 * typed: an old `.pptx` is zipped XML, so the same extractor that ingests
 * material also fills this corpus.
 */
class CatiExample extends Model
{
    /** @use HasFactory<CatiExampleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'summary',
        'sections',
        'tags',
        'source',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sections'  => 'array',
            'tags'      => 'array',
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

    /** The example's text for one section, when it has one. */
    public function section(SubmissionSectionKey $key): ?string
    {
        $value = $this->sections[$key->value] ?? null;

        return filled($value) ? (string) $value : null;
    }
}
