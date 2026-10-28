<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — CompanyService (a new company and its first administrator)
//  Location: app/Services/CompanyService.php
// ══════════════════════════════════════════════════════════════════

class CompanyService
{
    /**
     * Creates the company and its administrator in one step: both are saved, or neither.
     *
     * @param  array<string, mixed>  $data  already validated (see StoreCompanyRequest)
     */
    public function createWithAdmin(array $data, User $creator): Company
    {
        return DB::transaction(function () use ($data, $creator) {
            $company = Company::create([
                'name_en'                => $data['name_en'],
                'name_ar'                => $data['name_ar'],
                'status'                 => $data['status'],
                'users_limit'            => $data['users_limit'],
                'subscription_starts_at' => $data['subscription_starts_at'] ?? null,
                'subscription_ends_at'   => $data['subscription_ends_at'] ?? null,
                'contact_phone'          => $data['contact_phone'] ?? null,
                'contact_email'          => $data['contact_email'] ?? null,
                'notes'                  => $data['notes'] ?? null,
                'created_by'             => $creator->id,
            ]);

            User::query()->forceCreate([
                'company_id'        => $company->id,
                'name'              => $data['admin_name'],
                'email'             => Str::lower($data['admin_email']),
                'password'          => $data['admin_password'],
                'role'              => User::ROLE_COMPANY_ADMIN,
                'email_verified_at' => now(),
                'language'          => config('app.locale'),
                'created_by'        => $creator->id,
            ]);

            return $company;
        });
    }
}
