<?php

namespace App\Policies;

use App\Models\DocumentationPage;
use App\Models\User;

/**
 * A DocumentationPage has no authorization rule of its own — it delegates to
 * its `Notebook` (NotebookPolicy). Needed for
 * `EditsDocumentation::documentationView()` (`$user->can('update', $page)`) to
 * work — without a policy registered for DocumentationPage, the Gate would
 * default to "denied" and no one could edit any page.
 *
 * Note what this does NOT consult: the solutions the notebook is linked to.
 * Permission follows the caderno, so linking a notebook to a solution never
 * widens or narrows who may edit its pages.
 */
class DocumentationPagePolicy
{
    public function view(User $user, DocumentationPage $page): bool
    {
        return $user->can('view', $page->notebook);
    }

    public function update(User $user, DocumentationPage $page): bool
    {
        return $user->can('update', $page->notebook);
    }
}
