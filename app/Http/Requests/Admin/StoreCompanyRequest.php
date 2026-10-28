<?php

namespace App\Http\Requests\Admin;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

// A new company with its first administrator (super admin only).
class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'admin_email' => mb_strtolower(trim((string) $this->input('admin_email'))),
            'name_en'     => trim((string) $this->input('name_en')),
            'name_ar'     => trim((string) $this->input('name_ar')),
        ]);
    }

    public function rules(): array
    {
        return [
            ...CompanyRules::company(),
            'admin_name'     => ['required', 'string', 'max:120'],
            'admin_email'    => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            'admin_password' => ['required', 'string', Password::defaults()],
        ];
    }

    public function attributes(): array
    {
        return trans('companies.fields');
    }
}
