<?php

namespace App\Http\Requests;

use App\Enums\FeatureStatus;
use App\Enums\Platform;
use App\Enums\VerificationStatus;
use App\Models\Feature;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy check happens in the controller.
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            ...self::sharedRules($this->targetProjectId()),
        ];
    }

    /**
     * Rules shared by create and update. The category must belong to the same project.
     */
    public static function sharedRules(int $projectId): array
    {
        return [
            'description' => ['nullable', 'string', 'max:10000'],
            'category_id' => ['nullable', 'integer', Rule::exists('feature_categories', 'id')->where('project_id', $projectId)],
            'status' => [Rule::enum(FeatureStatus::class)],
            // Verified and failed come only from trusted test runs (POST /projects/{id}/test-runs).
            'verification_status' => [Rule::enum(VerificationStatus::class)->only([VerificationStatus::NotChecked, VerificationStatus::NeedsVerification])],
            'status_note' => ['nullable', 'string', 'max:2000'],
            // Leave out (or send []) for a feature on every platform of the project.
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['distinct', Rule::in(array_map(fn (Platform $p) => $p->value, Project::find($projectId)?->platformList() ?: Platform::cases()))],
            'acceptance_criteria' => ['array', 'max:50'],
            'acceptance_criteria.*' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'verification_status.enum' => 'Only trusted test runs can mark a feature verified or failed. Use not_checked or needs_verification.',
            'platforms.*.in' => 'Use one of the project\'s platforms (ios, android, web). Add the platform to the project first if it is missing.',
        ];
    }

    protected function targetProjectId(): int
    {
        $route = $this->route('project') ?? $this->route('feature');

        return $route instanceof Project ? $route->id : ($route instanceof Feature ? $route->project_id : 0);
    }
}
