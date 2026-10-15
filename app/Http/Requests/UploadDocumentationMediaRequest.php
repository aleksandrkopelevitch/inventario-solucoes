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
                // 100 MB. Raised from 20 MB on request: a page is where a
                // manual, a recording or a whole spreadsheet gets attached. The
                // server has to agree — PHP's upload_max_filesize/post_max_size
                // and nginx's client_max_body_size are set on the droplet, not
                // here — and media-library.max_file_size has to stay above it.
                'max:102400',
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
