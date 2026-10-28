<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — First administrator
//  Location: database/seeders/AdminUserSeeder.php
//
//  Creates the first account (the super admin) from .env (ADMIN_NAME, ADMIN_EMAIL, ADMIN_PASSWORD).
//  If ADMIN_PASSWORD is empty a strong random password is made and printed
//  ONCE on screen. Running it again never changes an existing account.
// ══════════════════════════════════════════════════════════════════

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = Str::lower((string) config('zavero.admin.email'));

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->warn("The administrator {$email} already exists - nothing changed.");

            return;
        }

        $password = config('zavero.admin.password') ?: Str::password(14, symbols: false);

        User::query()->forceCreate([
            'name'              => config('zavero.admin.name'),
            'email'             => $email,
            'password'          => $password,
            'role'              => User::ROLE_SUPER_ADMIN,
            'email_verified_at' => now(),
            'language'          => config('app.locale'),
        ]);

        $this->command?->info("Administrator created.  E-mail: {$email}");

        if (! config('zavero.admin.password')) {
            $this->command?->warn("Password (shown only now - write it down): {$password}");
        }
    }
}
