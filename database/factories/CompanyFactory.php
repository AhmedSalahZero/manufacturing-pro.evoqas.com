<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name_en'                => fake()->unique()->company(),
            'name_ar'                => 'شركة '.fake()->unique()->numberBetween(1000, 999999),
            'status'                 => 'active',
            'users_limit'            => 5,
            'subscription_starts_at' => today()->subMonth(),
            'subscription_ends_at'   => today()->addYear(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }

    /** The subscription ended yesterday: the company can look but not change anything. */
    public function expired(): static
    {
        return $this->state(fn () => ['subscription_ends_at' => today()->subDay()]);
    }
}
