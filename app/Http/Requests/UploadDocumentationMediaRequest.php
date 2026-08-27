<?php

namespace App\Http\Requests;

use App\Rules\PublicUrl;
use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentationMediaRequest extends FormRequest
{
    /** Same rule as save: only whoever edits the resource can upload media to the doc. */
    public function authorize(): bool
    {
        $model = $this->route('diagram') ?? $this->route('notebook');

        return $model !== null && $this->user()->can('update', $model);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // Two mutually exclusive modes (see EditsDocumentation::storeDocumentationMedia):
            // 'file' = multipart upload (image via Editor.js Image or file via
            // Attaches, both with config.field = 'file'); 'url' = image pasted from
            // an external site, which the server downloads and re-hosts (Image plugin byUrl).
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
                new PublicUrl,
            ],
        ];
    }
}
