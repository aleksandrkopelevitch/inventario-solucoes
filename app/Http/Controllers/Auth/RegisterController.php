<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class RegisterController extends Controller
{
    public function create(Request $request)
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request)
    {
        // The first account bootstraps the app as Admin; everyone else starts as Viewer.
        $role = User::query()->withTrashed()->exists() ? UserRole::Viewer : UserRole::Admin;

        $user = User::create([
            'role'     => $role->value,
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => $request->password,
        ]);

        // Hook for email verification — uncomment when ready:
        // $user->sendEmailVerificationNotification();

        Mail::to($user)->queue(new WelcomeMail($user));

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Bem-vindo ao Inventário de Soluções, ' . $user->name . '!',
            'type'    => 'success',
            'reload'  => 1,
            'goToURL' => route('profile.show'),
        ]);
    }
}
