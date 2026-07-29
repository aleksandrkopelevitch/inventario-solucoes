<?php

namespace App\Models;

use Database\Factories\DocumentationChatMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message in a DocumentationChat. `role` = user|assistant. `existing_content`
 * is the editor's live Markdown snapshot at send time (user messages only —
 * may include unsaved edits). `draft` carries the full proposed Markdown
 * replacement when an assistant reply includes one — never written to the
 * target directly, the user applies it into the editor and still has to
 * Salvar. `meta` audits the generation (tokens, context docs used/omitted,
 * requirements snapshot, error type on failure). `applied_at` is set once the
 * user loads this message's draft into the editor.
 */
class DocumentationChatMessage extends Model
{
    /** @use HasFactory<DocumentationChatMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'documentation_chat_id',
        'role',
        'content',
        'existing_content',
        'draft',
        'context_media_ids',
        'meta',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'context_media_ids' => 'array',
            'meta'              => 'array',
            'applied_at'        => 'datetime',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(DocumentationChat::class, 'documentation_chat_id');
    }
}
