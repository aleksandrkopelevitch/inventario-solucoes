<?php

namespace App\Support\Catalog;

use App\Models\Solution;
use App\Rules\AsciiSlug;
use Illuminate\Validation\Rule;

/**
 * The validation rules of a whole solution record, in ONE place.
 *
 * Two doors save a solution: the catalog's side panel (`Store/UpdateSolutionRequest`)
 * and the MCP write tools. The rules live here, rather than inside the Form
 * Requests, so the two cannot drift: a value one door accepts and the other
 * refuses is a record the screen and the chat disagree about. A Form Request
 * still validates every HTTP save (AGENTS.md § Security has no exception); it
 * just asks this class what the rules are.
 *
 * `$solution` is the record being edited (null when creating), standing in for
 * what the Form Requests used to read from the route.
 */
final class SolutionRules
{
    /** Keys only an HTTP form sends (an upload and its "Remover" switch). */
    public const UPLOAD_KEYS = ['logo', 'logo_action'];

    /** @return array<string, mixed> */
    public static function for(?Solution $solution = null): array
    {
        $unique = Rule::unique('solutions', 'slug');

        return [
            'name'                   => ['required', 'string', 'max:255'],
            'slug'                   => ['nullable', 'string', 'max:255', new AsciiSlug, $solution ? $unique->ignore($solution->getKey()) : $unique, Rule::notIn(Solution::RESERVED_SLUGS)],
            'description'            => ['nullable', 'string'],
            'vendor_company_id'      => ['nullable', 'integer', 'exists:companies,id'],
            'category'               => ['required', Rule::exists('attribute_options', 'value')->where('group', 'category')],
            'directorate'            => ['nullable', Rule::exists('attribute_options', 'value')->where('group', 'directorate')],
            'support_type'           => ['nullable', Rule::exists('attribute_options', 'value')->where('group', 'support_type')],
            'environment'            => ['nullable', Rule::exists('attribute_options', 'value')->where('group', 'environment')],
            'cloud'                  => ['nullable', Rule::exists('attribute_options', 'value')->where('group', 'cloud')],
            'contract_status'        => ['nullable', Rule::exists('attribute_options', 'value')->where('group', 'contract_status')],
            'support_operation_note' => ['nullable', 'string'],
            'criticality'            => ['nullable', Rule::exists('attribute_options', 'value')->where('group', 'criticality')],
            'status'                 => ['required', Rule::exists('attribute_options', 'value')->where('group', 'status')],
            'logo'                   => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            // `<x-forms.image-upload>`'s "Remover" button writes this hidden
            // field; it was never validated (so never read), which is why the
            // button silently did nothing. See `SolutionController::payload()`.
            'logo_action' => ['nullable', 'string', 'in:remove'],
        ];
    }
}
