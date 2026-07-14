<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentationMediaRequest extends FormRequest
{
    /** Mesma regra do save: só quem edita o recurso envia mídia pra doc. */
    public function authorize(): bool
    {
        $model = $this->route('integration') ?? $this->route('solution') ?? $this->route('group');

        return $model !== null && $this->user()->can('update', $model);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // Dois modos exclusivos (ver EditsDocumentation::storeDocumentationMedia):
            // 'file' = upload multipart (imagem via Editor.js Image ou arquivo via
            // Attaches, ambos com config.field = 'file'); 'url' = imagem colada de
            // site externo, que o servidor baixa e rehospeda (Image plugin byUrl).
            'file' => [
                'required_without:url',
                'file',
                'max:20480', // 20 MB
                'mimes:jpg,jpeg,png,gif,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,zip,json,yaml,yml,md',
            ],
            'url' => [
                'required_without:file',
                'url',
                'starts_with:http://,https://',
            ],
        ];
    }
}
