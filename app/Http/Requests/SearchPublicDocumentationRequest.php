<?php

namespace App\Http\Requests;

use App\Services\DocumentationSearchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The public documentation palette's query string. Public by design — the
 * opaque `public_token` in the path IS the authorization, exactly as for the
 * page and media routes next to it, so there is no user to authorize here.
 *
 * `q` is capped because it is scanned against every entry in the corpus, and
 * `filter.tag` is closed to the known facets so an unknown value can never
 * silently return "everything" instead of nothing.
 */
class SearchPublicDocumentationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q'              => ['nullable', 'string', 'max:120'],
            'filter'         => ['nullable', 'array'],
            'filter.section' => ['nullable', 'string', 'max:255'],
            'filter.tag'     => ['nullable', 'string', Rule::in(DocumentationSearchService::TAGS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.max'          => 'A busca aceita no máximo 120 caracteres.',
            'filter.tag.in'  => 'Filtro de conteúdo desconhecido.',
        ];
    }
}
