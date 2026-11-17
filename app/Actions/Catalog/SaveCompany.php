<?php

namespace App\Actions\Catalog;

use App\Models\Company;
use Illuminate\Support\Str;

/**
 * Creates or updates a company from an already-VALIDATED attribute array
 * (`CompanyRules`), generating the slug when none was given. Shared by the
 * catalog's side panel and the MCP write tools — see `SaveSolution`.
 */
class SaveCompany
{
    /** @param  array<string, mixed>  $data */
    public function handle(array $data, ?Company $company = null): Company
    {
        $data['slug'] = filled($data['slug'] ?? null)
            ? $data['slug']
            : ($company?->slug ?? $this->uniqueSlug($data['name'], $company));

        if ($company) {
            $company->update($data);

            return $company;
        }

        return Company::create($data);
    }

    private function uniqueSlug(string $name, ?Company $company): string
    {
        $base = Str::slug($name) ?: 'empresa';
        $slug = $base;
        $suffix = 1;

        while (in_array($slug, Company::RESERVED_SLUGS, true) || Company::where('slug', $slug)
            ->when($company, fn ($q) => $q->whereKeyNot($company->getKey()))
            ->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
