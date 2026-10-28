<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Company (a customer of the platform)
//  Location: app/Models/Company.php
//  Table: companies. Created only by the super admin (see Admin\CompanyController).
// ══════════════════════════════════════════════════════════════════

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    public const STATUSES = ['trial', 'active', 'suspended'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'users_limit'            => 'integer',
            'subscription_starts_at' => 'date',
            'subscription_ends_at'   => 'date',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** The name in the person's language. */
    public function displayName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $name = $locale === 'ar' ? $this->name_ar : $this->name_en;

        return $name ?: ($this->name_en ?: (string) $this->name_ar);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /** The subscription day has passed: everyone can look, nobody can change anything. */
    public function isReadOnly(): bool
    {
        return $this->subscription_ends_at !== null && $this->subscription_ends_at->lt(today());
    }

    /** Whole days left in the subscription (0 = last day, negative = ended), or null when there is no end date. */
    public function daysLeft(): ?int
    {
        return $this->subscription_ends_at ? (int) today()->diffInDays($this->subscription_ends_at, false) : null;
    }

    public function activeUsersCount(): int
    {
        return $this->users()->where('is_active', true)->count();
    }

    public function hasRoomForAnotherUser(): bool
    {
        return $this->activeUsersCount() < $this->users_limit;
    }
}
