<?php

namespace App\Http\Requests;

use App\Models\SolutionRelationship;

/**
 * Corrects a manual interconnection from its row on the solution page: its
 * direction, and possibly WHICH solution it connects to (re-pointing the row).
 * Same rules as recording one, with the row itself left out of the
 * one-per-pair check. `{relationship}` is resolved through
 * `Solution::resolveChildRouteBinding()`, so it always involves the route's
 * solution.
 */
class UpdateSolutionRelationshipRequest extends StoreSolutionRelationshipRequest
{
    protected function ignored(): ?SolutionRelationship
    {
        return $this->route('relationship');
    }
}
