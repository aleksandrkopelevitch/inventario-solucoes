<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Remove uma ligação existente da chain (botão "desligar" do editor de
 * aresta no data-viz F3) — sem corpo, só o índice em `chain.edges` na rota.
 * É o que permite deixar um bloco sem interligação: remover a única ligação
 * que o mantinha conectado ao resto do grafo não remove o nó em si.
 */
class RemoveIntegrationChainEdgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $integration = $this->route('integration');

        return $integration instanceof Integration
            && ($this->user()?->can('update', $integration) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
