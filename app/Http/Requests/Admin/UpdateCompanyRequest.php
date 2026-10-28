<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

// Changes to a company (super admin only). The user limit cannot be set below the accounts already active.
class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name_en' => trim((string) $this->input('name_en')),
            'name_ar' => trim((string) $this->input('name_ar')),
        ]);
    }

    public function rules(): array
    {
        return CompanyRules::company($this->route('company')->activeUsersCount());
    }

    public function messages(): array
    {
        return ['users_limit.min' => trans('companies.limit_too_low', ['count' => $this->route('company')->activeUsersCount()])];
    }

    public function attributes(): array
    {
        return trans('companies.fields');
    }
}
