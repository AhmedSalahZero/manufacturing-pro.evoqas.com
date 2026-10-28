<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — LoginRequest
//  Location: app/Http/Requests/Auth/LoginRequest.php
//
//  Checks the e-mail and password typed on the sign-in page.
//    · 5 wrong tries (per e-mail + address) lock sign-in for 15 minutes.
//    · The same message is shown for "no such e-mail" and "wrong password",
//      so nobody can find out which e-mails have accounts.
//    · A suspended account (is_active = false) is refused with its own message,
//      but only AFTER the password was right.
// ══════════════════════════════════════════════════════════════════

class LoginRequest extends FormRequest
{
    private const MAX_TRIES = 5;

    private const LOCK_SECONDS = 15 * 60;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $user = User::query()->where('email', Str::lower(trim((string) $this->string('email'))))->first();

        if (! $user || ! Hash::check((string) $this->string('password'), $user->password)) {
            RateLimiter::hit($this->throttleKey(), self::LOCK_SECONDS);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if ($reason = $user->accessDenialReason()) {
            throw ValidationException::withMessages(['email' => __($reason)]);
        }

        RateLimiter::clear($this->throttleKey());

        Auth::login($user, $this->boolean('remember'));
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $user;
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_TRIES)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
        ]);
    }

    public function throttleKey(): string
    {
        return 'login:'.Str::transliterate(Str::lower((string) $this->string('email'))).'|'.$this->ip();
    }
}
