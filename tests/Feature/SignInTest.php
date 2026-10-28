<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// Sign in and sign out. Run:  php artisan test --filter=SignInTest
class SignInTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Test-Pass-2026';

    public function test_the_sign_in_page_opens_for_a_guest(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    }

    public function test_the_site_address_leads_a_guest_to_sign_in_and_a_signed_in_person_home(): void
    {
        $this->get('/')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/home');
    }

    public function test_a_correct_e_mail_and_password_signs_in(): void
    {
        $user = User::factory()->create(['email' => 'mona@example.com']);

        $this->post('/login', ['email' => 'mona@example.com', 'password' => self::PASSWORD])->assertRedirect('/home');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_the_e_mail_is_not_case_sensitive_and_spaces_are_ignored(): void
    {
        $user = User::factory()->create(['email' => 'mona@example.com']);

        $this->post('/login', ['email' => '  MONA@Example.com ', 'password' => self::PASSWORD]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        User::factory()->create(['email' => 'mona@example.com']);

        $this->from('/login')->post('/login', ['email' => 'mona@example.com', 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    }

    public function test_an_unknown_e_mail_gets_the_very_same_message(): void
    {
        $this->from('/login')->post('/login', ['email' => 'nobody@example.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    }

    public function test_a_suspended_account_cannot_sign_in(): void
    {
        User::factory()->suspended()->create(['email' => 'mona@example.com']);

        $this->from('/login')->post('/login', ['email' => 'mona@example.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => __('auth.suspended')]);

        $this->assertGuest();
    }

    public function test_five_wrong_tries_lock_the_sign_in_even_for_the_right_password(): void
    {
        User::factory()->create(['email' => 'mona@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'mona@example.com', 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => 'mona@example.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_e_mail_and_password_are_required(): void
    {
        $this->post('/login', [])->assertSessionHasErrors(['email', 'password']);
        $this->post('/login', ['email' => 'not-an-email', 'password' => 'x'])->assertSessionHasErrors('email');
    }

    public function test_signing_out_works(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_a_page_for_signed_in_people_sends_a_guest_to_sign_in(): void
    {
        $this->get('/home')->assertRedirect('/login');
    }

    public function test_a_signed_in_person_is_sent_home_from_the_sign_in_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/home');
    }

    public function test_a_suspended_person_is_signed_out_on_the_next_click(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/home')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get('/home')->assertRedirect('/login');
        $this->assertGuest();
    }
}
