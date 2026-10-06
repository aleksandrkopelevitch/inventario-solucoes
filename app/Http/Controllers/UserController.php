<?php

namespace App\Http\Controllers;

use App\Actions\GrantPersonAccess;
use App\Enums\AccessModule;
use App\Enums\UserRole;
use App\Http\Requests\InviteUserRequest;
use App\Http\Requests\RevokeUserAccessRequest;
use App\Http\Requests\UpdateUserAccessRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Mail\UserInvitationMail;
use App\Models\User;
use App\View\Components\People\Access;
use App\View\Components\People\Accounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * Accounts as accounts: inviting one, and changing its role.
 *
 * There is no self-registration in this app — every account is created by an
 * admin, and the invited person sets their own password through the existing
 * password-reset flow (reusing `Password::createToken()`/`ResetPasswordController`
 * rather than a separate invite-token system).
 *
 * The SCREEN this used to own is gone. "Usuários" was a modal in the sidebar
 * menu, about an e-mail rather than about a person, because `people` and `users`
 * were unrelated tables. Access is now an attribute of a Person
 * (`PersonAccessController`, on their own page) and the roster lives at
 * `/people/accounts`, so what is left here are the two endpoints that are about
 * the ACCOUNT and not about whose it is:
 *
 * - `store()` — invite somebody by e-mail. It CREATES their catalog row as well
 *   (or reuses the one already filed under that e-mail), because an account born
 *   without a person is what made "vincular uma conta que já existe" a routine
 *   gesture rather than a repair. This is the e-mail delivery; a person's page
 *   hands the same thing over as a link.
 * - `update()` — the role, reachable from the accounts list and from the
 *   person's own Acesso card, which is why the refusals live in
 *   `UpdateUserRoleRequest` rather than in either screen.
 * - `updateAccess()` — the account's level in each module (Leitor/Editor),
 *   from the same two screens.
 */
class UserController extends Controller
{
    /**
     * Changes an account's role in place.
     *
     * The role used to be settable only at invite time, which left promoting a
     * viewer to editor — and taking the admin off someone who left — as a
     * database edit. The one refusal (your own account) is in
     * `UpdateUserRoleRequest::after()`, so this stays a save and a slot — and
     * that single rule is also what keeps an admin in the system, since only
     * an admin asking about SOMEBODY ELSE can demote one.
     */
    public function update(UpdateUserRoleRequest $request, User $user): JsonResponse
    {
        $user->update(['role' => $request->validated('role')]);

        return response()->json([
            'type'           => 'success',
            'message'        => "\"{$user->name}\" agora é {$user->fresh()->role->label()}.",
            'updatableSlots' => [Accounts::slot()],
        ]);
    }

    /**
     * Sets the account's level in one or more modules (`AccessModule`).
     *
     * Answers with both screens that show the levels — the roster and, when
     * the account belongs to somebody, that person's Acesso card; a slot whose
     * id is not on the page is a no-op in `ajax-slot.js`.
     */
    public function updateAccess(UpdateUserAccessRequest $request, User $user): JsonResponse
    {
        foreach ($request->levels() as $module => $level) {
            $user->setAccessLevel(AccessModule::from($module), $level);
        }

        $user->save();

        $changed = collect($request->levels())
            ->map(fn ($level, $module) => AccessModule::from($module)->shortLabel() . ': ' . $level->label())
            ->implode(', ');

        return response()->json([
            'type'           => 'success',
            'message'        => "\"{$user->name}\" — {$changed}.",
            'updatableSlots' => array_values(array_filter([
                Accounts::slot(),
                $user->person ? Access::slot($user->person) : null,
            ])),
        ]);
    }

    /**
     * Switches an account off: soft-deleted, unlinked from its person, its access
     * link cleared (`GrantPersonAccess::revokeAccount()`).
     *
     * It answers from the ROSTER because an account does not need a Person — the
     * orphans had nowhere else to be revoked from. A soft delete is what "revoke"
     * means here: the person stops being able to log in (the auth provider's
     * default scope stops resolving their session) while the submissions and
     * chats they authored keep pointing at a row that exists. Erasing an account
     * for real is a different job with a different blast radius.
     */
    public function destroy(RevokeUserAccessRequest $request, User $user, GrantPersonAccess $access): JsonResponse
    {
        // The person's own card has to hear about it too, and it is on another
        // screen — `ajax-slot.js` no-ops on an id that is not in the document, so
        // sending it whenever there IS a person is free.
        $person = $user->person;

        $access->revokeAccount($user);

        return response()->json([
            'type'           => 'success',
            'message'        => "Acesso de \"{$user->name}\" removido.",
            'updatableSlots' => array_values(array_filter([
                Accounts::slot(),
                $person ? Access::slot($person->fresh()) : null,
            ])),
        ]);
    }

    /**
     * Invites somebody by e-mail — the account and their catalog row, linked.
     *
     * Identity is the action's (`GrantPersonAccess::invite()`); what is left here
     * is DELIVERY, which is the only thing that distinguishes this door from a
     * person's Acesso card: the same destination, reached by e-mail instead of by
     * a link handed over in a Teams thread.
     */
    public function store(InviteUserRequest $request, GrantPersonAccess $access): JsonResponse
    {
        $user = $access->invite(
            $request->validated('name'),
            $request->validated('email'),
            $request->enum('role', UserRole::class),
        );

        $setPasswordUrl = route('password.reset', [
            'token' => Password::createToken($user),
            'email' => $user->email,
        ]);

        Mail::to($user)->queue(new UserInvitationMail($user, $setPasswordUrl));

        return response()->json([
            'type' => 'success',
            // Names the person, because THAT is the new part: the account is
            // not a loose e-mail any more, it belongs to a catalog row.
            'message'        => "Convite enviado para \"{$user->email}\" — conta vinculada a \"{$user->person->name}\".",
            'updatableSlots' => [Accounts::slot()],
        ]);
    }
}
