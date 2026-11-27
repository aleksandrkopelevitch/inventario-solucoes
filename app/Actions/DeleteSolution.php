<?php

namespace App\Actions;

use App\Models\Solution;

/**
 * Removes a solution from the catalog without taking the records around it.
 *
 * Everything that merely LINKS to a solution — its owners, the cadernos that
 * document it, the relationships the documentation and people recorded —
 * cascades on its pivot or row, so the link goes and the person, the caderno
 * and the page stay. A person is a first-class record here, not a child of the
 * solution; so is a company, which the solution points AT
 * (`vendor_company_id`) rather than owning. A UML diagram that bound the
 * solution keeps drawing the element, now bound to nothing.
 *
 * An Action of its own, though it is one line today, because both the solution
 * page and the revert of an MCP create delete through it: whatever a delete
 * has to sweep next belongs here once, not in two callers. (Until 2026-11-27
 * it rewrote the committee's AS IS / TO BE canvases, whose nodes named a
 * solution with no foreign key; those are uploaded pictures now.)
 */
class DeleteSolution
{
    public function handle(Solution $solution): void
    {
        $solution->delete();
    }
}
