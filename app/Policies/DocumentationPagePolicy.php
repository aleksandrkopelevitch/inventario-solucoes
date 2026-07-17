<?php

namespace App\Policies;

use App\Models\DocumentationPage;
use App\Models\User;

/**
 * A DocumentationPage has no authorization rule of its own — it delegates to
 * the container (`Solution` via SolutionPolicy, or `DocumentationGroup` via
 * DocumentationGroupPolicy). Needed for `EditsDocumentation::documentationView()`
 * (`$user->can('update', $page)`) to work — without a policy registered for
 * DocumentationPage, the Gate would default to "denied" and no one could
 * edit any page.
 */
class DocumentationPagePolicy
{
    public function view(User $user, DocumentationPage $page): bool
    {
        return $user->can('view', $page->container);
    }

    public function update(User $user, DocumentationPage $page): bool
    {
        return $user->can('update', $page->container);
    }
}
