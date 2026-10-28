<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

// A new person in my company (company administrator only). Counts against the company's user limit.
class StoreCompanyUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isCompanyAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'name'  => trim((string) $this->input('name')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:120'],
            'email'     => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            'password'  => ['required', 'string', Password::defaults()],
            'job_title' => ['nullable', 'string', 'max:100'],
            'role'      => ['required', Rule::in([User::ROLE_USER, User::ROLE_COMPANY_ADMIN])],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! $validator->errors()->isNotEmpty() && ! $this->user()->company->hasRoomForAnotherUser()) {
                $validator->errors()->add('email', __('users.limit_reached', ['limit' => $this->user()->company->users_limit]));
            }
        }];
    }

    public function attributes(): array
    {
        return trans('users.fields');
    }
}
