<?php

namespace App\Models;

use Database\Factories\SubmissionMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of the interview.
 *
 * `drafts` is a LIST of `{key, markdown}` rather than the single `draft`
 * column the documentation assistant uses: a turn here can legitimately
 * propose content for more than one section at once.
 */
class SubmissionMessage extends Model
{
    /** @use HasFactory<SubmissionMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'submission_chat_id',
        'role',
        'content',
        'drafts',
        'source_ids',
        'meta',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'drafts'     => 'array',
            'source_ids' => 'array',
            'meta'       => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(SubmissionChat::class, 'submission_chat_id');
    }

    public function hasDrafts(): bool
    {
        return filled($this->drafts);
    }
}
