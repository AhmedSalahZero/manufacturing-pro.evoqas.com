<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// A new study starts with a name only. Run: php artisan test --filter=ProjectsTest
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canUseStudies();
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255']];
    }

    public function attributes(): array
    {
        return ['name' => __('projects.fields.name')];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }
}
