<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Gerenciar os valores dos atributos (Categoria, Status, Diretoria etc.) é
 * configuração transversal ao catálogo inteiro — restrito a administradores,
 * mesma trilha de `SolutionPolicy::create/update` (seção 15).
 */
class AttributeOptionPolicy
{
    public function manage(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
