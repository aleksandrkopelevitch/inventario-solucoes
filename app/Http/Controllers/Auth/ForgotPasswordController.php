<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function create()
    {
        return view('auth.forgot-password');
    }

    /**
     * Always responds with the same generic success message, whether or not
     * the email matches an account — surfacing `Password::INVALID_USER`
     * distinctly from `RESET_LINK_SENT` would let anyone enumerate which
     * emails have an account just by trying this form.
     */
    public function store(ForgotPasswordRequest $request)
    {
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => __('passwords.sent'),
            'type'    => 'success',
        ]);
    }
}
