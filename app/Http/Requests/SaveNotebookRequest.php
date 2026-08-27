<?php

namespace App\Http\Requests;

use App\Models\Notebook;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Nome de um Caderno — usado tanto pra criar quanto pra renomear (o slug nunca
 * muda, pela mesma razão de sempre: a URL de um caderno é estável).
 *
 * As soluções que ele documenta não vêm por aqui — têm endpoint próprio
 * (`notebooks.solutions`, SyncNotebookSolutionsRequest), porque vincular é uma
 * decisão à parte de nomear e cada uma responde com um conjunto de slots
 * diferente.
 */
class SaveNotebookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $notebook = $this->route('notebook');

        return $notebook ? $this->user()->can('update', $notebook) : $this->user()->can('create', Notebook::class);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Dê um nome ao caderno.',
        ];
    }
}
