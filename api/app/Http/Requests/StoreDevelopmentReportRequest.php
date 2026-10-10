<?php

namespace App\Http\Requests;

use App\Enums\FeatureStatus;
use App\Enums\ReportConfidence;
use App\Enums\TestOutcome;
use App\Enums\TicketStatus;
use App\Enums\WaitingOn;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload for a structured implementation report (see README "Agent reports").
 * Reports cannot claim verification; only implementation status per feature.
 */
class StoreDevelopmentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy check happens in the controller.
    }

    /**
     * `unresolved` entries may be plain strings or `{text, waiting_on?, decision_note?}`; strings become `{text}`.
     */
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('unresolved'))) {
            $this->merge(['unresolved' => array_map(fn ($entry) => is_string($entry) ? ['text' => $entry] : $entry, $this->input('unresolved'))]);
        }
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('project'));
    }

    public static function rulesFor(Project $project): array
    {
        return [
            'summary' => ['required', 'string', 'max:10000'],
            'agent' => ['nullable', 'string', 'max:100'],
            'reported_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'confidence' => ['nullable', Rule::enum(ReportConfidence::class)],
            'limitations' => ['nullable', 'string', 'max:10000'],

            'git_branch' => ['nullable', 'string', 'max:255'],
            'git_commit' => ['nullable', 'string', 'regex:/^[0-9a-f]{7,64}$/i'],
            'diff_ref' => ['nullable', 'string', 'max:2048'],

            'features' => ['array', 'max:100'],
            'features.*.id' => ['required', 'integer', 'distinct', Rule::exists('features', 'id')->where('project_id', $project->id)],
            'features.*.status' => ['nullable', Rule::enum(FeatureStatus::class)],
            'features.*.note' => ['nullable', 'string', 'max:2000'],

            'changed_files' => ['array', 'max:2000'],
            'changed_files.*.path' => ['required', 'string', 'max:1024'],
            'changed_files.*.change' => ['nullable', Rule::in(['added', 'modified', 'deleted', 'renamed'])],

            'tests' => ['array', 'max:200'],
            'tests.*.command' => ['required', 'string', 'max:1000'],
            'tests.*.outcome' => ['required', Rule::enum(TestOutcome::class)],
            'tests.*.summary' => ['nullable', 'string', 'max:5000'],

            'unresolved' => ['array', 'max:100'],
            'unresolved.*' => ['array'],
            'unresolved.*.text' => ['required', 'string', 'max:2000'],
            'unresolved.*.waiting_on' => ['nullable', Rule::enum(WaitingOn::class)],
            'unresolved.*.decision_note' => ['nullable', 'string', 'max:2000'],

            'resolves' => ['array', 'max:200'],
            'resolves.*' => ['integer', 'distinct', Rule::exists('unresolved_items', 'id')->where('project_id', $project->id)],

            'tickets' => ['array', 'max:100'],
            'tickets.*.id' => ['required', 'integer', 'distinct', Rule::exists('tickets', 'id')->where('project_id', $project->id)],
            'tickets.*.status' => ['nullable', Rule::enum(TicketStatus::class)],
            'tickets.*.note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Keep only the documented keys inside each list item.
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);

        if ($key !== null) {
            return $data;
        }

        foreach (['features' => ['id', 'status', 'note'], 'changed_files' => ['path', 'change'], 'tests' => ['command', 'outcome', 'summary'], 'tickets' => ['id', 'status', 'note'], 'unresolved' => ['text', 'waiting_on', 'decision_note']] as $list => $keys) {
            if (isset($data[$list])) {
                $data[$list] = array_map(fn ($item) => array_intersect_key($item, array_flip($keys)), $data[$list]);
            }
        }

        return $data;
    }
}
