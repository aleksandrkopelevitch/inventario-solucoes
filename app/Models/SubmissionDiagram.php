<?php

namespace App\Models;

use App\Enums\SubmissionDiagramKind;
use Database\Factories\SubmissionDiagramFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One of the four pictures behind a submission
 * (`App\Enums\SubmissionDiagramKind`): AS IS, TO BE, C4 C1 and C2, each an
 * image the person uploads.
 *
 * AS IS / TO BE used to be drawn on an in-app canvas (a `chain` of blocks);
 * it was removed on 2026-11-27 with the catalog's topology drawings, and every
 * drawing's rendered PNG became its upload
 * (docs-and-specs/done/solution-relationships-from-uml.md, phase 4b). Nothing is
 * derived from these pictures: who talks to whom is the documentation's.
 */
class SubmissionDiagram extends Model implements HasMedia
{
    /** @use HasFactory<SubmissionDiagramFactory> */
    use HasFactory, InteractsWithMedia;

    /** The uploaded picture. `singleFile()`. */
    public const UPLOAD_COLLECTION = 'submission_diagram';

    protected $fillable = [
        'submission_id',
        'kind',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SubmissionDiagramKind::class,
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::UPLOAD_COLLECTION)->singleFile();
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** The picture that represents this slot on a slide and on the page. */
    public function picture(): ?Media
    {
        return $this->getFirstMedia(self::UPLOAD_COLLECTION);
    }

    /** Whether this slot has anything to show — what the checklist reads. */
    public function isFilled(): bool
    {
        return $this->picture() !== null;
    }
}
