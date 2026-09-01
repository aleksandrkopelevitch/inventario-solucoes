<?php

namespace App\Http\Controllers;

use App\Actions\GrantPersonAccess;
use App\Http\Requests\InviteUserRequest;
use App\Http\Requests\RevokeUserAccessRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Mail\UserInvitationMail;
use App\Models\User;
use App\View\Components\People\Access;
use App\View\Components\People\Accounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

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
 * - `store()` — invite somebody who is NOT in the catalog. Still needed, and the
 *   seeded `admin@leomadeiras.com.br` is the proof: an account does not require
 *   a Person. This is the e-mail delivery; a person's page hands over a link.
 * - `update()` — the role, reachable from the accounts list and from the
 *   person's own Acesso card, which is why the refusals live in
 *   `UpdateUserRoleRequest` rather than in either screen.
 */
class UserController extends Controller
{
    /**
     * Changes an account's role in place.
     *
     * The role used to be settable only at invite time, which left promoting a
     * viewer to editor — and taking the admin off someone who left — as a
     * database edit. Both refusals (your own account, the last admin) are in
     * `UpdateUserRoleRequest::after()`, so this stays a save and a slot.
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

    public function store(InviteUserRequest $request): JsonResponse
    {
        $user = User::create([
            'name'  => $request->validated('name'),
            'email' => $request->validated('email'),
            'role'  => $request->validated('role'),
            // Unusable until the invite link below sets a real one — the
            // `password` column is NOT NULL and the invited person never
            // chooses this value.
            'password' => Str::random(40),
        ]);

        $setPasswordUrl = route('password.reset', [
            'token' => Password::createToken($user),
            'email' => $user->email,
        ]);

        Mail::to($user)->queue(new UserInvitationMail($user, $setPasswordUrl));

        return response()->json([
            'type'           => 'success',
            'message'        => "Convite enviado para \"{$user->email}\".",
            'updatableSlots' => [Accounts::slot()],
        ]);
    }
}
