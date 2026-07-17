<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContextDocumentRequest extends FormRequest
{
    /** Only whoever edits the Solution manages its context documents. */
    public function authorize(): bool
    {
        $solution = $this->route('solution');

        return $solution !== null && $this->user()->can('update', $solution);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // Formats Claude ingests natively (PDF/image as an attachment,
            // text embedded in the prompt — see DocumentationDraftService).
            'file' => [
                'required',
                'file',
                'max:20480', // 20 MB
                'mimes:pdf,png,jpg,jpeg,gif,webp,txt,md,csv,json,yaml,yml',
            ],
        ];
    }
}
