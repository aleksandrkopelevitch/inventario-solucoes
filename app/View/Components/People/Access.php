<?php

namespace App\View\Components\People;

use App\Enums\UserRole;
use App\Models\Person;
use App\Models\User;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Acesso" card on the person's detail page: whether this human can log in, as
 * what, and the link that lets them set their own password.
 *
 * It sits beside "Sistemas" and "Anotações" because it is the same kind of fact
 * — something true about this person — and it replaces a modal that was about
 * nobody in particular. Most people in the catalog will never have an account
 * (106 of 108 rows in dev have no email at all), so the card's ordinary state is
 * "sem acesso", stated plainly rather than hidden.
 *
 * Renderable as an updatable slot: `Access::slot($person)`.
 */
class Access extends Component
{
    use Renderable;

    public const DOM_ID = 'person-access-slot';

    public function __construct(public Person $person) {}

    public static function slot(Person $person): array
    {
        return (new static($person))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        // Explicit, because this component is rendered both from the page (a
        // single-row fetch, where strict mode does NOT arm) and from a mutation
        // response — a lazy load would work in dev and cost a query forever.
        $this->person->loadMissing('user');

        $account = $this->person->user;
        $viewer = auth()->user();

        // `manage`, not `update` on the person: an EDITOR may rewrite this
        // person's job title and must not be able to hand out an account. The
        // card is read-only for them — it still says whether the person has
        // access, which is a fact about the catalog, not a lever.
        $canManage = $viewer?->can('manage', User::class) ?? false;

        return view('components.people.access', [
            'domId'     => self::DOM_ID,
            'person'    => $this->person,
            'account'   => $account,
            'canManage' => $canManage,
            // Nobody edits their own role from anywhere — same rule
            // `UpdateUserRoleRequest` enforces and `UserList` mirrors, so the
            // select is simply absent on your own account rather than failing
            // when pressed.
            'canChangeRole' => $canManage && $account !== null && ! $account->is($viewer),
            'roleOptions'   => array_map(
                fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label()],
                UserRole::cases(),
            ),
            'accessUrl'   => route('people.access.store', $this->person),
            'linkUrl'     => route('people.access.link', $this->person),
            'revokeUrl'   => route('people.access.destroy', $this->person),
            'roleUrl'     => $account ? route('people.access.role', [$this->person, $account]) : null,
            'refreshUrl'  => $account ? route('people.access.link.refresh', [$this->person, $account]) : null,
            'killLinkUrl' => $account ? route('people.access.link.destroy', [$this->person, $account]) : null,
            // The live link itself, shown once and copyable. It is a URL that
            // opens a password screen, not a session (see AccessLinkController),
            // which is what makes showing it on an admin's screen reasonable.
            'accessLink' => $account?->hasLiveAccessToken()
                ? route('access.show', $account->access_token)
                : null,
            'expiresAt' => $account?->hasLiveAccessToken() ? $account->access_token_expires_at : null,
            // Accounts nobody has claimed — what "vincular conta existente"
            // offers. Only loaded for an admin looking at a person who has none.
            'orphanAccounts' => $canManage && $account === null
                ? User::query()->whereDoesntHave('person')->orderBy('name')->get(['id', 'name', 'email'])
                : collect(),
        ]);
    }
}
