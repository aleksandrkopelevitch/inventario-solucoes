<?php

namespace App\Models;

use App\Enums\ContextExtractionState;
use App\Enums\FlowspecAttachmentKind;
use Database\Factories\FlowspecAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One piece of context attached to a FlowspecChat — see the migration for why
 * this is chat-scoped and why there are exactly two kinds of it.
 */
class FlowspecAttachment extends Model
{
    /** @use HasFactory<FlowspecAttachmentFactory> */
    use HasFactory;

    protected $fillable = [
        'flowspec_chat_id',
        'kind',
        'label',
        'reference_type',
        'reference_id',
        'media_id',
        'content',
        'extraction_state',
        'extraction_note',
        'sensitive_findings',
        'is_flowspec_reference',
        'token_estimate',
    ];

    protected function casts(): array
    {
        return [
            'kind'                  => FlowspecAttachmentKind::class,
            'extraction_state'      => ContextExtractionState::class,
            'sensitive_findings'    => 'array',
            'is_flowspec_reference' => 'boolean',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(FlowspecChat::class, 'flowspec_chat_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** A DocumentationPage, for kind=document. @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** Text ready to inline into the prompt, as opposed to riding along as a native attachment. */
    public function hasInlineText(): bool
    {
        return $this->extraction_state === ContextExtractionState::Done && filled($this->content);
    }

    /**
     * The file goes to the model as a native attachment (PDF/image) rather than
     * as inlined text — `Skipped` is that decision, not a failure.
     */
    public function isNativeAttachment(): bool
    {
        return $this->extraction_state === ContextExtractionState::Skipped && $this->media !== null;
    }

    /** Something in this attachment looks like a credential — surfaced next to it, never removed. */
    public function hasSensitiveFindings(): bool
    {
        return filled($this->sensitive_findings);
    }
}
