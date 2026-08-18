<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding gathered material: either an uploaded file or a link.
 *
 * The mime list is `file`-based, not `image` — the whole point is that a
 * previous CATI deck (`.pptx`) is readable material. SVG is accepted here for
 * the same reason documentation media accepts it: it is a diagram format, and
 * it is never served back from the public disk.
 */
class StoreSubmissionSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('submission')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file'  => ['required_without:url', 'file', 'mimes:pdf,pptx,docx,txt,md,csv,json,png,jpg,jpeg,webp,svg', 'max:20480'],
            'url'   => ['required_without:file', 'nullable', 'url', 'max:2048'],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.mimes'            => 'Formato não aceito. Envie PDF, PPTX, DOCX, texto ou imagem.',
            'file.max'              => 'O arquivo passa de 20 MB.',
            'file.required_without' => 'Escolha um arquivo ou informe um link.',
            'url.required_without'  => 'Escolha um arquivo ou informe um link.',
        ];
    }
}
