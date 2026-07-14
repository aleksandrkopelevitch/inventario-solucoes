<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Persiste a posição (x,y) de um hub arrastado no mapa global do ecossistema
 * (`ecosystem-map.js::startHubDrag`) — auto-save silencioso a cada arraste,
 * sem painel/formulário. Mesma permissão de editar a própria Solução.
 */
class UpdateSolutionMapPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('solution')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
        ];
    }
}
