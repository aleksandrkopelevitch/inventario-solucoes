<?php

namespace App\View\Components\Users;

use App\Enums\UserRole;
use App\Models\User;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * List + invite form, inside the "Usuários" area (single Modal — see
 * `UserController`). Own slot so a new invite refreshes just this list — and so
 * a role change refreshes the badge it just changed.
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
        $users = User::query()->orderBy('name')->get(['id', 'name', 'email', 'role']);

        return view('components.users.list', [
            'users' => $users,
            // The roles a badge can be switched to, in the enum's own order.
            'roleOptions' => array_map(
                fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label()],
                UserRole::cases(),
            ),
            // Which rows carry the select at all: every one but your own, which
            // is the same line `UpdateUserRoleRequest::after()` draws. Answered
            // here as well as there on purpose — a control that only fails once
            // you press it is a worse answer than a row that never offered one —
            // and the request stays the authority; this is the affordance.
            //
            // Nothing extra for "the last admin": only an admin sees this panel,
            // so if the last admin is on screen it is the viewer's own row,
            // already withheld above.
            'changeable' => $users
                ->mapWithKeys(fn (User $user) => [$user->id => ! $user->is(auth()->user())])
                ->all(),
        ]);
    }
}
