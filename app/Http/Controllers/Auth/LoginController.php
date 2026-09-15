<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function create()
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request)
    {
        if (! Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return response()->json([
            // Where they can actually go. A `Reader` — the tier Entra SSO
            // provisions, and one an admin can hand out by hand too — has no
            // inventory to land on, and `profile.show` is inside it.
            'redirect' => $request->user()->role->canReadInventory()
                ? route('profile.show')
                : route('docs.index'),
        ]);
    }

    public function destroy()
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login.create');
    }
}
