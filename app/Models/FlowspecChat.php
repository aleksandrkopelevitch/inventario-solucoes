<?php

namespace App\Models;

use Database\Factories\FlowspecChatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversa do gerador de flowSpec (F8). Opcionalmente vinculada à Integration
 * que receberá o pipeline gerado (`integrations.generated_flowspec`).
 */
class FlowspecChat extends Model
{
    /** @use HasFactory<FlowspecChatFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'integration_id',
        'title',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(FlowspecMessage::class)->orderBy('id');
    }

    /**
     * A resposta do assistente é gerada em job (GenerateFlowspecReply): o chat
     * está "gerando…" enquanto a última mensagem for do usuário — inclusive a
     * falha vira mensagem do assistente (failed()), então isto sempre resolve.
     */
    public function isAwaitingReply(): bool
    {
        // reorder(): messages() já ordena por id asc, que venceria o latest().
        return $this->messages()->reorder('id', 'desc')->value('role') === 'user';
    }
}
