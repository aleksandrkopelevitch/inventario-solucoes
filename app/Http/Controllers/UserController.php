<?php

namespace App\Http\Controllers;

use App\Http\Requests\InviteUserRequest;
use App\Mail\UserInvitationMail;
use App\Models\User;
use App\View\Components\Users\UserList;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * "Usuários" area — admin-only. There is no self-registration in this app:
 * every account is created here, by an admin, and the invited person sets
 * their own password through the existing password-reset flow (reusing
 * `Password::createToken()`/`ResetPasswordController` rather than building a
 * separate invite-token system). Only exists inside `#main-modal` (never its
 * own page), opened from the sidebar user menu — see `user-menu.blade.php`.
 */
class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('manage', User::class);

        return response()->json([
            'content' => view('users.manage')->render(),
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
            'updatableSlots' => [UserList::slot()],
        ]);
    }
}
