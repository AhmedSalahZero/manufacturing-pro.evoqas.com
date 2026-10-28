<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// Language (English / Arabic) and theme (light / dark). Run:  php artisan test --filter=PreferencesTest
class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_sees_english_left_to_right_and_light_by_default(): void
    {
        $this->get('/login')
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('data-theme="light"', false);
    }

    public function test_a_guest_can_choose_arabic_and_it_is_right_to_left_from_the_first_paint(): void
    {
        $this->post('/preferences', ['language' => 'ar'])->assertRedirect();

        $this->get('/login')
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'ar'));
    }

    public function test_a_guest_can_choose_dark(): void
    {
        $this->post('/preferences', ['theme' => 'dark']);

        $this->get('/login')->assertSee('data-theme="dark"', false)->assertInertia(fn (Assert $page) => $page->where('theme', 'dark'));
    }

    public function test_server_messages_follow_the_language(): void
    {
        $this->post('/preferences', ['language' => 'ar']);

        $this->from('/login')->post('/login', ['email' => 'nobody@example.com', 'password' => 'x'])
            ->assertSessionHasErrors(['email' => __('auth.failed', [], 'ar')]);
    }

    public function test_a_signed_in_person_s_choice_is_saved_on_the_account(): void
    {
        $user = User::factory()->create(['language' => 'en', 'theme' => 'light']);

        $this->actingAs($user)->post('/preferences', ['language' => 'ar', 'theme' => 'dark'])->assertRedirect();

        $this->assertSame('ar', $user->fresh()->language);
        $this->assertSame('dark', $user->fresh()->theme);
    }

    public function test_a_signed_in_person_gets_their_saved_language_on_every_screen_and_device(): void
    {
        $user = User::factory()->create(['language' => 'ar', 'theme' => 'dark']);

        $this->actingAs($user)->get('/home')
            ->assertSee('dir="rtl"', false)
            ->assertSee('data-theme="dark"', false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->where('locale', 'ar')
                ->where('auth.user.language', 'ar')
                ->where('auth.user.theme', 'dark'));
    }

    public function test_the_account_wins_over_what_the_session_remembers(): void
    {
        $user = User::factory()->create(['language' => 'en']);

        $this->withSession(['locale' => 'ar'])->actingAs($user)->get('/home')->assertSee('lang="en"', false);
    }

    public function test_only_known_values_are_accepted(): void
    {
        $this->post('/preferences', ['language' => 'fr'])->assertSessionHasErrors('language');
        $this->post('/preferences', ['theme' => 'purple'])->assertSessionHasErrors('theme');
    }
}
