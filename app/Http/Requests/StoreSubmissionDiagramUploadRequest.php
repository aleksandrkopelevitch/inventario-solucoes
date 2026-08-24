<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesChainOwner;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The picture that fills a C4 slot (`SubmissionDiagramKind::C4Context` /
 * `C4Container`).
 *
 * SVG is accepted here and NOT under the avatar/logo rules, for the reason
 * documentation media already accepts it: it is a diagram format, it is what
 * a C4 tool exports, and it is never served from the public disk — it goes
 * into a MediaLibrary collection behind an authenticated route. Laravel's
 * bare `image` rule would reject it outright, so the rule is `file` + an
 * explicit mime list, same as `StoreSubmissionSourceRequest`.
 */
class StoreSubmissionDiagramUploadRequest extends FormRequest
{
    use AuthorizesChainOwner;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:8192'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'image.required' => 'Escolha uma imagem do diagrama.',
            'image.mimes'    => 'Formato não aceito. Envie PNG, JPG, WEBP ou SVG.',
            'image.max'      => 'A imagem passa de 8 MB.',
        ];
    }
}
