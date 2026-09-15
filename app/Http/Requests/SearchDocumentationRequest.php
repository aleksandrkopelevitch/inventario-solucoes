<?php

namespace App\Http\Requests;

use App\Services\DocumentationSearchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The documentation palette's query string, on either read-only surface — the
 * magic link and the internal knowledge base share one palette, so they share
 * one request.
 *
 * `authorize()` is `true` because authorization is not a property of the QUERY
 * on either of them: on the magic link the opaque `public_token` in the path IS
 * the authorization, and on `/docs` it is the route's `auth` middleware plus the
 * caderno being published. Both are settled before this runs, and both are
 * settled by the path rather than by what was typed into the box.
 *
 * `q` is capped because it is scanned against every entry in the corpus, and
 * both `filter.tag` and `filter.scopes` are closed to their known vocabularies
 * so an unknown value can never silently return "everything" instead of
 * nothing.
 */
class SearchDocumentationRequest extends FormRequest
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
            // WHERE the query looks. Closed to the known buckets for the same
            // reason `tag` is: an unknown value must not quietly widen the
            // search back to everything.
            //
            // It has to be declared to EXIST at all — `validated()` returns only
            // what the rules name, so an undeclared `filter.scopes` is silently
            // dropped and every scoped search answers as if unscoped.
            'filter.scopes'   => ['nullable', 'array'],
            'filter.scopes.*' => ['string', Rule::in(DocumentationSearchService::SCOPES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.max'              => 'A busca aceita no máximo 120 caracteres.',
            'filter.tag.in'      => 'Filtro de conteúdo desconhecido.',
            'filter.scopes.*.in' => 'Escopo de busca desconhecido.',
        ];
    }
}
