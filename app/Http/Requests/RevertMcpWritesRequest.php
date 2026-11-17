<?php

namespace App\Http\Requests;

use App\Models\McpWrite;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reverting every not-yet-reverted MCP write the list's filters select.
 *
 * At least one filter is REQUIRED: "Reverter seleção" on an unfiltered list
 * would undo every change every chat ever made, which is never what somebody
 * pressing it after one bad conversation means.
 */
class RevertMcpWritesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('revert', McpWrite::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = McpWriteFilterRules::rules();
        $rules['filter'] = ['required', 'array'];
        $rules['filter.user'][] = 'required_without_all:filter.client,filter.from,filter.to';

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'filter.required'                  => 'Filtre por pessoa, conector ou período antes de reverter em lote.',
            'filter.user.required_without_all' => 'Filtre por pessoa, conector ou período antes de reverter em lote.',
        ];
    }

    /** @return array<string, string> */
    public function filters(): array
    {
        return McpWriteFilterRules::filled($this->validated('filter', []));
    }
}
