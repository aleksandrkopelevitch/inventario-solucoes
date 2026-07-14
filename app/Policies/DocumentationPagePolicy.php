<?php

namespace App\Policies;

use App\Models\DocumentationPage;
use App\Models\User;

/**
 * Uma DocumentationPage não tem regra de autorização própria — ela delega
 * pro container (`Solution` via SolutionPolicy, ou `DocumentationGroup` via
 * DocumentationGroupPolicy). Necessária pra `EditsDocumentation::documentationView()`
 * (`$user->can('update', $page)`) funcionar — sem policy registrada pra
 * DocumentationPage, o Gate cairia no padrão "negado" e ninguém conseguiria
 * editar página nenhuma.
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
