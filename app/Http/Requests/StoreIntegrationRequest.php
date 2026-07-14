<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cria uma Integration nova a partir da solução do contexto — só o nome
 * (opcional). Chain inicial é {nodes: [raiz], edges: []}; blocos, ligações e
 * status/renome ficam por conta do data-viz F3 daí em diante.
 */
class StoreIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Integration::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
