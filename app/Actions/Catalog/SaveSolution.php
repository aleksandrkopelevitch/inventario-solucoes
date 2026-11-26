<?php

namespace App\Actions\Catalog;

use App\Actions\Documentation\RewriteSolutionBindings;
use App\Actions\Documentation\SyncPageSolutionLinks;
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
    public function __construct(
        private readonly RewriteSolutionBindings $rewriteBindings,
        private readonly SyncPageSolutionLinks $syncLinks,
    ) {}

    /**
     * The slug is the address a UML diagram binds a solution by
     * (`"solution": "<slug>"`), so both ends of its life reach the
     * documentation: a rename rewrites the bindings, and a solution created
     * after a page that already named it gets that page's relationships.
     *
     * @param  array<string, mixed>  $data
     */
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
            $old = $solution->slug;
            $solution->update($data);

            if ($solution->wasChanged('slug')) {
                $this->rewriteBindings->handle($old, $solution->slug);
            }

            return $solution;
        }

        $solution = Solution::create($data);
        $this->syncLinks->forPagesNaming($solution->slug);

        return $solution;
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
