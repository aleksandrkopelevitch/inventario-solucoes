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
        'flowspec_example_id',
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

    /** The corpus example this message was promoted into, if any. */
    public function promotedExample(): BelongsTo
    {
        return $this->belongsTo(FlowspecExample::class, 'flowspec_example_id');
    }

    /** Whether this message has already been promoted to a corpus example. */
    public function isPromoted(): bool
    {
        return $this->flowspec_example_id !== null;
    }
}
