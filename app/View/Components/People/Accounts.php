<?php

namespace App\View\Components\People;

use App\Enums\UserRole;
use App\Models\User;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The accounts list — "quem tem acesso" — as a view of the Pessoas module
 * rather than a modal in the sidebar menu.
 *
 * It survives the move for one reason: **an account does not need a Person.**
 * `admin@leomadeiras.com.br` comes from `DatabaseSeeder` and never will have a
 * catalog row, so a screen that only listed "people who have accounts" would
 * leave the one account that cannot be locked out with no screen at all. Every
 * row here says which person it belongs to, and an orphan says so and offers
 * nothing but the truth — linking one is done from that person's page.
 *
 * Renderable as an updatable slot: `Accounts::slot()`.
 */
class Accounts extends Component
{
    use Renderable;

    public const DOM_ID = 'people-accounts-slot';

    public static function slot(): array
    {
        return (new static)->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $accounts = User::query()
            ->with('person:id,name,slug,user_id')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'access_token', 'access_token_expires_at']);

        return view('components.people.accounts', [
            'domId'       => self::DOM_ID,
            'roleOptions' => array_map(
                fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label()],
                UserRole::cases(),
            ),
            // The role is editable HERE as well as on a person's Acesso card,
            // and that is not duplication — it is the only place an ORPHAN
            // account's role can be changed at all. `admin@leomadeiras.com.br`
            // has no Person and never will, so a roster that only reported
            // roles would have left the one account that cannot be locked out
            // with no way to change its own tier either.
            //
            // Withheld on your own row, mirroring `UpdateUserRoleRequest`: a
            // select that can take away the panel you are standing in is a
            // missing affordance, not an error to discover by pressing it.
            'changeable' => $accounts
                ->mapWithKeys(fn (User $user) => [$user->id => ! $user->is(auth()->user())])
                ->all(),
            // `with('person')`, not a lazy walk: this is a multi-row hydration,
            // which is exactly where strict mode arms.
            'accounts' => $accounts,
        ]);
    }
}
