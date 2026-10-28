<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Choose a new password (from the e-mailed link)
//  Location: app/Http/Controllers/Auth/NewPasswordController.php
//
//  The password rule lives in one place: AppServiceProvider (Password::defaults).
//  After a successful reset the person's other sessions are ended
//  (their remember-me token changes) and they sign in with the new password.
// ══════════════════════════════════════════════════════════════════

class NewPasswordController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->query('email'),
            'token' => $request->route('token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            [
                ...$request->only('email', 'password', 'password_confirmation', 'token'),
                'email' => mb_strtolower(trim((string) $request->string('email'))),
            ],
            function ($user) use ($request) {
                $user->forceFill([
                    'password'       => Hash::make($request->string('password')->toString()),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return redirect()->route('login')->with('status', __('auth.password_reset_done'));
    }
}
