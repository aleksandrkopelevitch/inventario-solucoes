<?php

namespace App\View\Components\Users;

use App\Models\User;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * List + invite form, inside the "Usuários" area (single Modal — see
 * `UserController`). Own slot so a new invite refreshes just this list.
 */
class UserList extends Component
{
    use Renderable;

    public static function slot(): array
    {
        return (new static)->toSlot('users-list-slot');
    }

    public function render(): View
    {
        return view('components.users.list', [
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'role']),
        ]);
    }
}
