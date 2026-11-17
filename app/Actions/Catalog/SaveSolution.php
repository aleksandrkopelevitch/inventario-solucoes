<?php

namespace App\Actions\Catalog;

use App\Models\Solution;
use Illuminate\Support\Str;

/**
 * Creates or updates a solution from an already-VALIDATED attribute array
 * (`SolutionRules`), filling in what the record cannot be saved without.
 *
 * Shared by the catalog's side panel (`SolutionController::store/update`) and
 * the MCP write tools, so a solution born in a chat gets the same slug and the
 * same defaults as one born on the screen. Uploads are not its business: the
 * controller turns a logo into `logo_path` before calling, and the MCP sends
 * no files.
 */
class SaveSolution
{
    /** @param  array<string, mixed>  $data */
    public function handle(array $data, ?Solution $solution = null): Solution
    {
        $data['slug'] = filled($data['slug'] ?? null)
            ? $data['slug']
            : ($solution?->slug ?? $this->uniqueSlug($data['name'], $solution));

        // NOT NULL columns with no default: keep the submitted value, else the current one (editing), else a
        // fixed default — must always exist as a seeded option in `attribute_options`.
        $data['support_type'] = $data['support_type'] ?? $solution?->support_type ?? 'third_party';
        $data['contract_status'] = $data['contract_status'] ?? $solution?->contract_status ?? 'unknown';

        if ($solution) {
            $solution->update($data);

            return $solution;
        }

        return Solution::create($data);
    }

    private function uniqueSlug(string $name, ?Solution $solution): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (in_array($slug, Solution::RESERVED_SLUGS, true) || Solution::where('slug', $slug)
            ->when($solution, fn ($q) => $q->whereKeyNot($solution->getKey()))
            ->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
