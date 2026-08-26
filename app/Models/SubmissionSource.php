<?php

namespace App\Models;

use App\Enums\ContextExtractionState;
use App\Enums\SubmissionSourceKind;
use Database\Factories\SubmissionSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One piece of gathered material behind a submission.
 *
 * An `Inventory` source carries no file at all — it points at a Solution,
 * Diagram or DocumentationPage through `reference`, and the interview
 * reads that record live rather than a copy of it that would go stale.
 */
class SubmissionSource extends Model
{
    /** @use HasFactory<SubmissionSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'kind',
        'label',
        'url',
        'media_id',
        'reference_type',
        'reference_id',
        'extracted_text',
        'extraction_state',
        'extraction_note',
        'sensitive_findings',
    ];

    protected function casts(): array
    {
        return [
            'kind'               => SubmissionSourceKind::class,
            'extraction_state'   => ContextExtractionState::class,
            'sensitive_findings' => 'array',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** Something in this file looks like a credential — surfaced next to it, never removed. */
    public function hasSensitiveFindings(): bool
    {
        return filled($this->sensitive_findings);
    }

    /** Text is available to inline into a prompt (as opposed to riding along as a native attachment). */
    public function hasText(): bool
    {
        return $this->extraction_state === ContextExtractionState::Done && filled($this->extracted_text);
    }
}
