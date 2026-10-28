<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// "Forgot password" and "Choose a new password". Run:  php artisan test --filter=PasswordResetTest
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_forgot_password_page_opens(): void
    {
        $this->get('/forgot-password')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_a_link_is_sent_to_an_active_account(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', __('auth.reset_link_sent'));

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_an_unknown_e_mail_gets_the_same_answer_and_no_mail(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status', __('auth.reset_link_sent'));

        Notification::assertNothingSent();
    }

    public function test_a_suspended_account_gets_the_same_answer_but_no_link(): void
    {
        Notification::fake();
        $user = User::factory()->suspended()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', __('auth.reset_link_sent'));

        Notification::assertNothingSent();
    }

    public function test_the_reset_page_opens_from_the_link(): void
    {
        $this->get('/reset-password/some-token?email=mona@example.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ResetPassword')->where('token', 'some-token')->where('email', 'mona@example.com'));
    }

    public function test_a_good_new_password_is_saved_and_the_old_one_stops_working(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77',
        ])->assertRedirect('/login')->assertSessionHas('status', __('auth.password_reset_done'));

        $this->assertTrue(Hash::check('Brand-New-Pass-77', $user->fresh()->password));
        $this->assertFalse(Hash::check('Test-Pass-2026', $user->fresh()->password));
    }

    public function test_a_weak_password_is_refused(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        foreach (['short1A', 'alllowercase123', 'NoNumbersHereAtAll'] as $weak) {
            $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => $weak, 'password_confirmation' => $weak])
                ->assertSessionHasErrors('password');
        }

        $this->assertTrue(Hash::check('Test-Pass-2026', $user->fresh()->password));
    }

    public function test_a_password_that_is_not_confirmed_is_refused(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');
    }

    public function test_a_wrong_or_used_link_is_refused(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'not-a-real-token', 'email' => $user->email,
            'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('Test-Pass-2026', $user->fresh()->password));
    }
}
