<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContextDocumentRequest extends FormRequest
{
    /** Only whoever edits the Solution manages its context documents. */
    public function authorize(): bool
    {
        $notebook = $this->route('notebook');

        return $notebook !== null && $this->user()->can('update', $notebook);
    }

    /**
     * Two ways in, one endpoint: a picked FILE, or a long paste the composer
     * turned into context (App\Actions\Documentation\AttachContextText).
     *
     * The paste arrives as `text` rather than as a synthesized upload so the
     * server can recognize a pipeline and minify it before storing — and so a
     * failure here is a validation error about a paste, not about a file the
     * person never chose.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // Formats Claude ingests natively (PDF/image as an attachment,
            // text embedded in the prompt — see ContextDocumentResolver).
            'file' => [
                'required_without:text',
                'file',
                'max:20480', // 20 MB
                'mimes:pdf,png,jpg,jpeg,gif,webp,txt,md,csv,json,yaml,yml',
            ],
            'text' => [
                'required_without:file',
                'string',
                // Sized for a whole pasted pipeline, like the F8 composer's own
                // ceiling. What actually reaches the prompt is bounded further
                // down by doc_budget_chars, which truncates rather than refuses.
                'max:' . (int) config('services.documentation_ai.max_pasted_chars'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'text.max' => 'O texto colado é longo demais para virar um documento de contexto.',
        ];
    }
}
