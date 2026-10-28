<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// The first administrator (php artisan db:seed). Run:  php artisan test --filter=AdminSeederTest
class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_administrator_from_the_settings(): void
    {
        config(['zavero.admin' => ['name' => 'Owner', 'email' => 'Owner@Zavero.test', 'password' => 'Owner-Pass-2026']]);

        $this->seed(AdminUserSeeder::class);

        $user = User::query()->where('email', 'owner@zavero.test')->firstOrFail();
        $this->assertSame('Owner', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Owner-Pass-2026', $user->password));
    }

    public function test_with_no_password_set_a_strong_random_one_is_made(): void
    {
        config(['zavero.admin' => ['name' => 'Owner', 'email' => 'owner@zavero.test', 'password' => null]]);

        $this->seed(AdminUserSeeder::class);

        $user = User::query()->firstOrFail();
        $this->assertFalse(Hash::check('', $user->password));
        $this->assertGreaterThan(40, strlen($user->password)); // stored hashed, never as text
    }

    public function test_running_it_again_never_changes_an_existing_account(): void
    {
        config(['zavero.admin' => ['name' => 'Owner', 'email' => 'owner@zavero.test', 'password' => 'Owner-Pass-2026']]);
        $this->seed(AdminUserSeeder::class);

        config(['zavero.admin.password' => 'Another-Pass-2027', 'zavero.admin.name' => 'Someone else']);
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::query()->count());
        $user = User::query()->firstOrFail();
        $this->assertSame('Owner', $user->name);
        $this->assertTrue(Hash::check('Owner-Pass-2026', $user->password));
    }

    public function test_the_new_administrator_can_sign_in(): void
    {
        config(['zavero.admin' => ['name' => 'Owner', 'email' => 'owner@zavero.test', 'password' => 'Owner-Pass-2026']]);
        $this->seed(AdminUserSeeder::class);

        $this->post('/login', ['email' => 'owner@zavero.test', 'password' => 'Owner-Pass-2026'])->assertRedirect('/home');
    }
}
