<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

// Small rules of the User model. Run:  php artisan test --filter=UserTest
class UserTest extends TestCase
{
    public function test_initials_use_the_first_two_names(): void
    {
        $this->assertSame('MA', (new User(['name' => 'Mona Ali']))->initials());
        $this->assertSame('MA', (new User(['name' => '  mona   ahmed   ali ']))->initials());
        $this->assertSame('M', (new User(['name' => 'Mona']))->initials());
        $this->assertSame('مع', (new User(['name' => 'منى علي']))->initials());
        $this->assertSame('?', (new User(['name' => '']))->initials());
    }

    public function test_language_falls_back_to_the_application_default(): void
    {
        $user = new User();
        $user->language = 'ar';
        $this->assertSame('ar', $user->preferredLanguage());

        $user->language = 'fr';
        $this->assertSame(config('app.locale'), $user->preferredLanguage());

        $user->language = null;
        $this->assertSame(config('app.locale'), $user->preferredLanguage());
    }

    public function test_theme_falls_back_to_light(): void
    {
        $user = new User();
        $user->theme = 'dark';
        $this->assertSame('dark', $user->preferredTheme());

        $user->theme = 'purple';
        $this->assertSame('light', $user->preferredTheme());
    }

    public function test_the_password_and_token_are_never_shown(): void
    {
        $array = (new User(['name' => 'x', 'email' => 'x@x.test']))->forceFill(['password' => 'secret', 'remember_token' => 'abc'])->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
    }
}
