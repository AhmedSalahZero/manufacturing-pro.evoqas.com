<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — User (an account that can sign in)
//  Location: app/Models/User.php
//
//  language   'en' | 'ar'        the person's saved language
//  theme      'light' | 'dark'   the person's saved theme
//  is_active  false = suspended: cannot sign in, and is signed out at once
// ══════════════════════════════════════════════════════════════════

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN   = 'super_admin';
    public const ROLE_COMPANY_ADMIN = 'company_admin';
    public const ROLE_USER          = 'user';

    protected $fillable = [
        'name',
        'email',
        'password',
        'language',
        'theme',
        'is_active',
        'job_title',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'is_active'         => 'boolean',
            'password'          => 'hashed',
        ];
    }

    /** The plans (projects) this person owns. */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** The links this person created to share a project. */
    public function sharingLinks(): HasMany
    {
        return $this->hasMany(SharingLink::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isCompanyAdmin(): bool
    {
        return $this->role === self::ROLE_COMPANY_ADMIN;
    }

    /** May this account work with studies? Only people of a company; the super admin manages companies. */
    public function canUseStudies(): bool
    {
        return ! $this->isSuperAdmin() && $this->company_id !== null;
    }

    /**
     * Why this account may not use the application right now, as a translation key, or null when it may.
     * Checked at sign-in AND on every request, so suspending a person or a company takes effect at once.
     */
    public function accessDenialReason(): ?string
    {
        if (! $this->is_active) {
            return 'auth.suspended';
        }

        if (! $this->isSuperAdmin() && $this->company_id !== null && $this->company?->isSuspended()) {
            return 'auth.company_suspended';
        }

        return null;
    }

    public function preferredLanguage(): string
    {
        return in_array($this->language, config('zavero.locales'), true) ? $this->language : config('app.locale');
    }

    public function preferredTheme(): string
    {
        return $this->theme === 'dark' ? 'dark' : 'light';
    }

    /** "Mona Ali" → "MA" ; "Mona" → "M" ; Arabic names work the same way. */
    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim((string) $this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = array_map(fn (string $p) => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($parts, 0, 2));

        return implode('', $letters) ?: '?';
    }
}
