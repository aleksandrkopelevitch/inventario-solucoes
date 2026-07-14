<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class ForgotPasswordController extends Controller
{
    public function create()
    {
        return view('auth.forgot-password');
    }

    public function store(ForgotPasswordRequest $request)
    {
        $status = Password::sendResetLink($request->only('email'));

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        return response()->json([
            'message' => __($status),
            'type'    => 'success',
        ]);
    }
}
