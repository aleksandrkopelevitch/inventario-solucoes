<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveDocumentationPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = $this->route('solution') ?? $this->route('group');

        return $model !== null && $this->user()->can('update', $model);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['up', 'down'])],
        ];
    }
}
