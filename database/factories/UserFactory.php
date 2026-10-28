<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name'              => fake()->name(),
            'email'             => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // The password used by every test account: Test-Pass-2026
            'password'          => static::$password ??= Hash::make('Test-Pass-2026'),
            'language'          => 'en',
            'theme'             => 'light',
            'is_active'         => true,
            'role'              => User::ROLE_USER,
            'company_id'        => Company::factory(),
            'remember_token'    => \Illuminate\Support\Str::random(10),
        ];
    }

    /** The platform owner: belongs to no company. */
    public function superAdmin(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_SUPER_ADMIN, 'company_id' => null]);
    }

    /** The administrator of a company. */
    public function companyAdmin(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_COMPANY_ADMIN]);
    }

    /** A member of the given company. */
    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
