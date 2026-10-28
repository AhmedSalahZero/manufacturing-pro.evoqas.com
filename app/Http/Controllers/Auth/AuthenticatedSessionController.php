<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Sign in / sign out
//  Location: app/Http/Controllers/Auth/AuthenticatedSessionController.php
// ══════════════════════════════════════════════════════════════════

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => session('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        return redirect()->intended(route('home', absolute: false));
    }

    public function destroy(Request $request): HttpResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // A full page load, so nothing of the signed-in screen stays in memory.
        return Inertia::location(route('login'));
    }
}
