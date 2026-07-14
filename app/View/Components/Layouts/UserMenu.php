<?php

namespace App\View\Components\Layouts;

use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Menu de contexto no canto inferior esquerdo da sidebar (avatar + nome do
 * usuário logado). Slot atualizável para que editar o perfil (nome/e-mail/
 * avatar) via `profile.edit` reflita aqui sem reload — ver `ProfileController::update()`.
 */
class UserMenu extends Component
{
    use Renderable;

    public const DOM_ID = 'sidebar-user-menu-slot';

    public static function slot(): array
    {
        return (new static)->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.layouts.user-menu', ['domId' => self::DOM_ID]);
    }
}
