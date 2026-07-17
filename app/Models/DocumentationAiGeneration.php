<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A documentation generation request from "Assiste IA": the UI dispatches the
 * GenerateDocumentationDraft job and polls `status` until `completed`/`failed`.
 * The `result` (Markdown) is loaded into the editor for review — nothing is
 * written to the page until the user saves. `target` is the DocumentationPage
 * (or Integration) being generated; `solution` owns the context documents.
 */
class DocumentationAiGeneration extends Model
{
    protected $fillable = [
        'target_type',
        'target_id',
        'solution_id',
        'user_id',
        'status',
        'prompt',
        'context_media_ids',
        'existing_content',
        'result',
        'error',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'context_media_ids' => 'array',
            'meta'              => 'array',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function solution(): BelongsTo
    {
        return $this->belongsTo(Solution::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
