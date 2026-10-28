<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — EnsureActiveUser
//  Location: app/Http/Middleware/EnsureActiveUser.php
//
//  Checked on EVERY request of a signed-in person: if the account was
//  suspended while they were working, they are signed out at once and
//  sent to the sign-in page with the reason.
// ══════════════════════════════════════════════════════════════════

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($reason = $user->accessDenialReason())) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __($reason));
        }

        return $next($request);
    }
}
