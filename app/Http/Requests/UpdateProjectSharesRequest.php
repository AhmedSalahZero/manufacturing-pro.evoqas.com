<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Who a study is shared with: colleagues of the same company, view only or view and edit.
class UpdateProjectSharesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('share', $this->route('project'));
    }

    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'shares'             => ['present', 'array'],
            'shares.*.user_id'   => [
                'required', 'integer', 'distinct',
                Rule::exists('users', 'id')
                    ->where('company_id', $project->company_id)
                    ->where('is_active', true)
                    ->whereNot('id', $project->user_id)
                    ->whereNot('role', User::ROLE_SUPER_ADMIN),
            ],
            'shares.*.can_edit'  => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['shares.*.user_id' => __('projects.share.person')];
    }
}
