<?php

namespace App\Http\Requests\Admin;

use App\Models\Company;
use Illuminate\Validation\Rule;

// The rules the company form shares between "new company" and "edit company".
final class CompanyRules
{
    public static function company(int $minUsers = 1): array
    {
        return [
            'name_en'                => ['required', 'string', 'max:150'],
            'name_ar'                => ['required', 'string', 'max:150'],
            'status'                 => ['required', Rule::in(Company::STATUSES)],
            'users_limit'            => ['required', 'integer', 'min:'.max(1, $minUsers), 'max:1000'],
            'subscription_starts_at' => ['nullable', 'date_format:Y-m-d'],
            'subscription_ends_at'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:subscription_starts_at'],
            'contact_phone'          => ['nullable', 'string', 'max:30'],
            'contact_email'          => ['nullable', 'email:rfc', 'max:150'],
            'notes'                  => ['nullable', 'string', 'max:5000'],
        ];
    }
}
