<?php

namespace App\Models;

use Database\Factories\FlowspecMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensagem de um FlowspecChat. `role` = user|assistant|system. `flow_spec`
 * carrega o pipeline gerado quando houver; `meta` audita a geração (exemplos
 * usados, tokens, tentativas de validação, status).
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
