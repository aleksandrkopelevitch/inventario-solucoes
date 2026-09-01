<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\GrantPersonAccess;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\GrantPersonAccessRequest;
use App\Http\Requests\LinkPersonAccountRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Models\Person;
use App\Models\User;
use App\View\Components\People\Access;
use App\View\Components\People\Accounts;
use Illuminate\Http\JsonResponse;

/**
 * A person's ACCESS: whether they can log in, as what, and the link that lets
 * them set their own password.
 *
 * It lives on the person's own page — beside the systems they own and the notes
 * about them — because that is what access is: an attribute of a human. Before
 * this it was a modal about nobody in particular, and `people`/`users` were
 * tables with no relation, so the app could list who could log in and could not
 * say who any of them were.
 *
 * Every action here answers to `UserPolicy::manage` (admin), never
 * `PersonPolicy::update` (admin OR editor) — the requests carry that, and it is
 * the one distinction this whole controller exists to keep. An editor curates
 * the catalog; handing out an account is not curation.
 */
class PersonAccessController extends Controller
{
    public function __construct(private readonly GrantPersonAccess $access) {}

    /**
     * Refuses a `{person}`/`{user}` pair that is not actually linked.
     *
     * `{user}` is a GLOBAL binding: `scopeBindings()` cannot help here, because
     * scoping resolves a child through a plural relation on the parent and a
     * person has ONE account (`user()`, a belongsTo). Without this an admin
     * could post person A's URL carrying person B's account id — every action
     * would land on B while the response handed back A's card, so the screen
     * would report a change that happened somewhere else. Not an escalation (an
     * admin may change either), just a URL that lies, which is worse to debug.
     */
    private function accountOf(Person $person, User $user): User
    {
        abort_unless($person->user_id === $user->getKey(), 404);

        return $user;
    }

    /** Creates the account and mints its first access link. */
    public function store(GrantPersonAccessRequest $request, Person $person): JsonResponse
    {
        $this->access->grant($person, $request->enum('role', UserRole::class));

        return $this->saved($person, 'Acesso concedido. Copie o link e envie para a pessoa.');
    }

    /**
     * Changes the role of the account behind this person.
     *
     * Reuses `UpdateUserRoleRequest` — the refusal that matters (nobody changes
     * their OWN role) is about the account, not about the screen it is changed
     * from, and duplicating it here is how the two surfaces would drift.
     */
    public function updateRole(UpdateUserRoleRequest $request, Person $person, User $user): JsonResponse
    {
        $this->accountOf($person, $user)->update(['role' => $request->validated('role')]);

        return $this->saved($person, "Perfil alterado para {$user->fresh()->role->label()}.");
    }

    /** Attaches an existing account (an orphan on the accounts list). */
    public function link(LinkPersonAccountRequest $request, Person $person): JsonResponse
    {
        $this->access->link($person, $request->account());

        return $this->saved($person, 'Conta vinculada a esta pessoa.');
    }

    /** Replaces the access link, which is also the only way to kill a leaked one. */
    public function refreshLink(Person $person, User $user): JsonResponse
    {
        $this->authorize('manage', User::class);

        $this->access->mintAccessToken($this->accountOf($person, $user));

        return $this->saved($person, 'Novo link gerado. O link anterior deixou de funcionar.');
    }

    /** Kills the link without touching the account. */
    public function destroyLink(Person $person, User $user): JsonResponse
    {
        $this->authorize('manage', User::class);

        $this->access->revokeAccessToken($this->accountOf($person, $user));

        return $this->saved($person, 'Link de acesso revogado.');
    }

    /** Ends access: the account is soft-deleted and unlinked (see the Action). */
    public function destroy(Person $person): JsonResponse
    {
        $this->authorize('manage', User::class);

        $this->access->revoke($person);

        return $this->saved($person, 'Acesso removido. A pessoa continua no catálogo.');
    }

    /**
     * Every mutation answers with BOTH the person's card and the accounts list.
     *
     * The list is on another screen (`/people/accounts`), and `ajax-slot.js`
     * no-ops on an id that is not in the document — so sending it always is free,
     * and forgetting it is what leaves "quem tem acesso" stale after somebody
     * was granted or revoked (§ Multiple different slots).
     */
    private function saved(Person $person, string $message): JsonResponse
    {
        $person = $person->fresh(['user']);

        return response()->json([
            'type'           => 'success',
            'message'        => $message,
            'updatableSlots' => [Access::slot($person), Accounts::slot()],
        ]);
    }
}
