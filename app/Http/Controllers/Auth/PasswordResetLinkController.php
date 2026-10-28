<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — "Forgot password": sends the reset link
//  Location: app/Http/Controllers/Auth/PasswordResetLinkController.php
//
//  The answer is always the same ("if this e-mail has an account, a link
//  is on its way"), so nobody can find out which e-mails have accounts.
//  A suspended account gets no link.
// ══════════════════════════════════════════════════════════════════

class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:190']]);

        Password::sendResetLink(
            ['email' => mb_strtolower(trim((string) $request->string('email')))],
            fn ($user, $token) => $user->is_active ? $user->sendPasswordResetNotification($token) : null,
        );

        return back()->with('status', __('auth.reset_link_sent'));
    }
}
