<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy check happens in the controller.
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'repository_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'regex:'.StoreProjectRequest::REPOSITORY_PATTERN],
            'tech_stack' => ['sometimes', 'nullable', 'string', 'max:255'],
            ...StoreProjectRequest::platformRules(),
            'mismatches_enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return (new StoreProjectRequest)->messages();
    }
}
