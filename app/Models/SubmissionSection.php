<?php

namespace App\Models;

use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use Database\Factories\SubmissionSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answered section of a submission. `content` is Markdown, read back
 * through `x-ui.markdown` (App\Support\MarkdownText — GFM, HTML stripped),
 * never through the GitBook renderer: these are short free-text fields, not
 * authored documentation pages.
 */
class SubmissionSection extends Model
{
    /** @use HasFactory<SubmissionSectionFactory> */
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'key',
        'content',
        'state',
        'provenance',
        'updated_by_id',
    ];

    protected function casts(): array
    {
        return [
            'key'        => SubmissionSectionKey::class,
            'state'      => SubmissionSectionState::class,
            'provenance' => 'array',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /** Blank content is the only thing the checklist counts as a gap — state alone can lie after an edit. */
    public function isAnswered(): bool
    {
        return filled($this->content);
    }
}
