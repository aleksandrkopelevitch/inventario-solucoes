<?php

namespace App\Support\Catalog;

use App\Enums\CompanyKind;
use App\Models\Company;
use App\Rules\AsciiSlug;
use Illuminate\Validation\Rule;

/**
 * The validation rules of a whole company record, shared by the catalog's side
 * panel (`Store/UpdateCompanyRequest`) and the MCP write tools — see
 * `SolutionRules` for why they live outside the Form Requests.
 */
final class CompanyRules
{
    /** Keys only an HTTP form sends (an upload and its "Remover" switch). */
    public const UPLOAD_KEYS = ['logo', 'logo_action'];

    /** @return array<string, mixed> */
    public static function for(?Company $company = null): array
    {
        $unique = Rule::unique('companies', 'slug');

        return [
            'name'    => ['required', 'string', 'max:255'],
            'slug'    => ['nullable', 'string', 'max:255', new AsciiSlug, $company ? $unique->ignore($company->getKey()) : $unique, Rule::notIn(Company::RESERVED_SLUGS)],
            'kind'    => ['required', Rule::enum(CompanyKind::class)],
            'website' => ['nullable', 'url', 'max:255'],
            'notes'   => ['nullable', 'string'],
            'logo'    => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            // `<x-forms.image-upload>`'s "Remover" button writes this hidden
            // field; it was never validated (so never read), which is why the
            // button silently did nothing. See `CompanyController::payload()`.
            'logo_action' => ['nullable', 'string', 'in:remove'],
        ];
    }
}
