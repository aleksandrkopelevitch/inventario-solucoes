<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding gathered material: an uploaded file, a link, or a long paste.
 *
 * The mime list is `file`-based, not `image` — the whole point is that a
 * previous CATI deck (`.pptx`) is readable material. SVG is accepted here for
 * the same reason documentation media accepts it: it is a diagram format, and
 * it is never served back from the public disk.
 *
 * `text` is what the composer sends when someone pastes more than
 * `cati.paste_threshold_chars`. Its ceiling is `cati.max_pasted_chars`, not
 * the chat message's 8000: a pasted document is not prose, and refusing it at
 * the prose limit would send the user back to saving it as a file first —
 * which is the friction the paste-to-attachment gesture exists to remove.
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
            'file'  => ['required_without_all:url,text', 'file', 'mimes:pdf,pptx,docx,txt,md,csv,json,png,jpg,jpeg,webp,svg', 'max:20480'],
            'url'   => ['required_without_all:file,text', 'nullable', 'url', 'max:2048'],
            'text'  => ['required_without_all:file,url', 'nullable', 'string', 'max:' . (int) config('services.cati.max_pasted_chars')],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $missing = 'Escolha um arquivo, informe um link ou cole um texto.';

        return [
            'file.mimes'                => 'Formato não aceito. Envie PDF, PPTX, DOCX, texto ou imagem.',
            'file.max'                  => 'O arquivo passa de 20 MB.',
            'file.required_without_all' => $missing,
            'url.required_without_all'  => $missing,
            'text.required_without_all' => $missing,
            'text.max'                  => 'O texto colado é grande demais. Anexe como arquivo.',
        ];
    }
}
