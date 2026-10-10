<?php

namespace App\Http\Requests;

use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    /**
     * Accepts http(s)/ssh/git URLs, scp-style "git@host:org/repo.git", or an absolute local path.
     */
    public const REPOSITORY_PATTERN = '~^(https?://|ssh://|git://|git@[\w.-]+:|/)\S+$~';

    public function authorize(): bool
    {
        return true; // Policy check happens in the controller.
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'repository_url' => ['nullable', 'string', 'max:2048', 'regex:'.self::REPOSITORY_PATTERN],
            'tech_stack' => ['nullable', 'string', 'max:255'],
            ...self::platformRules(),
            // PRD vs design tracking; on unless turned off.
            'mismatches_enabled' => ['boolean'],
        ];
    }

    /** Where the project ships: any of ios, android, web. */
    public static function platformRules(): array
    {
        return [
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['distinct', Rule::enum(Platform::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'repository_url.regex' => 'The repository must be an http(s), ssh or git URL, "git@host:org/repo" or an absolute path.',
            'platforms.*.enum' => 'Platforms must be ios, android or web.',
        ];
    }
}
