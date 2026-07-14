<?php

namespace App\Http\Requests;

use App\Models\DocumentationGroup;
use Illuminate\Foundation\Http\FormRequest;

/** Nome de um DocumentationGroup — usado tanto pra criar quanto renomear (o slug nunca muda). */
class SaveDocumentationGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $group = $this->route('group');

        return $group ? $this->user()->can('update', $group) : $this->user()->can('create', DocumentationGroup::class);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
