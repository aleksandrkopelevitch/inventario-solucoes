<?php

namespace App\Policies;

use App\Models\User;

/**
 * Managing attribute values (Category, Status, Directorate, etc.) is
 * configuration that cuts across the whole catalog — restricted to
 * administrators. Deliberately NARROWER than `SolutionPolicy::create/update`,
 * which an editor may perform: the options ARE the catalog's vocabulary, and a
 * category invented while filling one form is a category every other form then
 * offers.
 */
class AttributeOptionPolicy
{
    public function manage(User $user): bool
    {
        return $user->role->isAdmin();
    }
}
