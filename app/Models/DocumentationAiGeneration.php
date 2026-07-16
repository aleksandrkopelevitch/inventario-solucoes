<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Um pedido de geração de documentação pelo "Assiste IA": a UI dispara o job
 * GenerateDocumentationDraft e faz polling em `status` até `completed`/`failed`.
 * O `result` (Markdown) é carregado no editor para revisão — nada é gravado na
 * página até o usuário salvar. `target` é a DocumentationPage (ou Integration)
 * sendo gerada; `solution` é a dona dos documentos de contexto.
 */
class DocumentationAiGeneration extends Model
{
    protected $fillable = [
        'target_type',
        'target_id',
        'solution_id',
        'user_id',
        'status',
        'prompt',
        'context_media_ids',
        'existing_content',
        'result',
        'error',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'context_media_ids' => 'array',
            'meta'              => 'array',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function solution(): BelongsTo
    {
        return $this->belongsTo(Solution::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
