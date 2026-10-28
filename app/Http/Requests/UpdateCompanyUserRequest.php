<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

// Changes to a person of my company. You cannot suspend or demote yourself, and a
// suspended person can only come back while the company has room under its user limit.
class UpdateCompanyUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $me = $this->user();
        $target = $this->route('user');

        abort_unless($me?->isCompanyAdmin() && (int) $target->company_id === (int) $me->company_id && ! $target->isSuperAdmin(), 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name'      => trim((string) $this->input('name')),
            'is_active' => filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'role'      => ['required', Rule::in([User::ROLE_USER, User::ROLE_COMPANY_ADMIN])],
            'is_active' => ['required', 'boolean'],
            'password'  => ['nullable', 'string', Password::defaults()],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $me = $this->user();
            $target = $this->route('user');

            if ($target->is($me) && (! $this->boolean('is_active') || $this->input('role') !== User::ROLE_COMPANY_ADMIN)) {
                $validator->errors()->add('role', __('users.cannot_change_self'));

                return;
            }

            if ($this->boolean('is_active') && ! $target->is_active && ! $me->company->hasRoomForAnotherUser()) {
                $validator->errors()->add('is_active', __('users.limit_reached', ['limit' => $me->company->users_limit]));
            }
        }];
    }

    public function attributes(): array
    {
        return trans('users.fields');
    }
}
