<?php

namespace App\Models;

use Database\Factories\FlowspecMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message from a FlowspecChat. `role` = user|assistant|system. `flow_spec`
 * carries the generated pipeline when present; `meta` audits the generation
 * (examples used, tokens, validation attempts, status).
 */
class FlowspecMessage extends Model
{
    /** @use HasFactory<FlowspecMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'flowspec_chat_id',
        'role',
        'content',
        'flow_spec',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'flow_spec' => 'array',
            'meta'      => 'array',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(FlowspecChat::class, 'flowspec_chat_id');
    }

    /**
     * `content` reads as a raw JSON blob rather than prose — the shape
     * FlowspecGenerationService falls back to when the correction loop
     * exhausts every attempt without ever producing a recognized flowSpec
     * (`flow_spec` stays null in that case). Shared by Thread (renders it as
     * a code block instead of Markdown) and FlowspecPromptBuilder (collapses
     * older occurrences in the conversation history — see historySection()).
     */
    public function hasRawJsonContent(): bool
    {
        $trimmed = trim($this->content);

        return $trimmed !== '' && in_array($trimmed[0], ['{', '['], true);
    }
}
