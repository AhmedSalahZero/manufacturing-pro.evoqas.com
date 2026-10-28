<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// The name of the copy of a study.
class CopyProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('copy', $this->route('project'));
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255']];
    }

    public function attributes(): array
    {
        return ['name' => __('projects.fields.newName')];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }
}
