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
        'slide_content',
        'slide_source_hash',
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

    /**
     * The text the DECK should use.
     *
     * Falls back to the full section whenever the condensed version is missing
     * or was made from different content: printing a summary of a paragraph
     * that has since been rewritten is worse than printing the paragraph.
     */
    public function slideText(): ?string
    {
        if (blank($this->slide_content) || ! $this->slideContentIsFresh()) {
            return $this->content;
        }

        return $this->slide_content;
    }

    public function slideContentIsFresh(): bool
    {
        return filled($this->slide_source_hash)
            && $this->slide_source_hash === self::hashFor($this->content);
    }

    public static function hashFor(?string $content): string
    {
        return md5(trim((string) $content));
    }

    /** Blank content is the only thing the checklist counts as a gap — state alone can lie after an edit. */
    public function isAnswered(): bool
    {
        return filled($this->content);
    }
}
