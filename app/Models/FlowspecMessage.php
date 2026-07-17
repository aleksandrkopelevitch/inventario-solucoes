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
}
