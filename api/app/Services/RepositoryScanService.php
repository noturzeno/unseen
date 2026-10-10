<?php

namespace App\Services;

use App\Enums\SuggestionStatus;
use App\Models\Feature;
use App\Models\FeatureSuggestion;
use App\Models\Project;
use App\Models\RepositoryScan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stores a repository scan and turns its candidates into feature suggestions.
 *
 * Rules (rescans must be repeatable and never create duplicates):
 *  - Suggestions are unique per (project, normalised key). A rescan updates the
 *    existing suggestion's evidence instead of adding a new one.
 *  - Dismissed suggestions stay dismissed; accepted/merged ones stay linked to their feature.
 *  - Name/description/category of an already-reviewed suggestion are not overwritten,
 *    so a scan never replaces what a person confirmed.
 *  - A new suggestion whose key matches an existing feature's name gets `matched_feature_id`
 *    as a hint to merge. It is never merged automatically.
 *  - Nothing here creates or changes features.
 */
class RepositoryScanService
{
    /**
     * @param  array{scanner?: ?string, git_branch?: ?string, git_commit?: ?string,
     *     candidates: list<array{key: string, name: string, description?: ?string, category?: ?string,
     *     evidence: list<array{type: string, path: string, detail?: ?string, line?: ?int}>}>}  $data
     */
    public function record(Project $project, array $data, ?User $user): RepositoryScan
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $scan = $project->repositoryScans()->create([
                'user_id' => $user?->id,
                'scanner' => $data['scanner'] ?? null,
                'git_branch' => $data['git_branch'] ?? null,
                'git_commit' => $data['git_commit'] ?? null,
            ]);

            // Merge candidates that normalise to the same key.
            $candidates = collect($data['candidates'])
                ->map(fn ($c) => [...$c, 'key' => FeatureSuggestion::normalizeKey($c['key'])])
                ->filter(fn ($c) => $c['key'] !== '')
                ->groupBy('key')
                ->map(fn ($group) => [
                    ...$group->first(),
                    'evidence' => $group->flatMap(fn ($c) => $c['evidence'])->unique(fn ($e) => $e['type'].'|'.$e['path'].'|'.($e['detail'] ?? ''))->values()->take(50)->all(),
                ]);

            $existing = $project->featureSuggestions()->whereIn('key', $candidates->keys())->lockForUpdate()->get()->keyBy('key');
            $featuresByKey = $project->features()->get(['id', 'name'])
                ->groupBy(fn (Feature $f) => FeatureSuggestion::normalizeKey($f->name));

            $new = $updated = 0;

            foreach ($candidates as $key => $candidate) {
                $suggestion = $existing[$key] ?? null;

                if ($suggestion) {
                    $changes = ['evidence' => $candidate['evidence'], 'last_scan_id' => $scan->id];
                    if ($suggestion->status === SuggestionStatus::Pending) {
                        $changes += [
                            'name' => $candidate['name'],
                            'description' => $candidate['description'] ?? $suggestion->description,
                            'suggested_category' => $candidate['category'] ?? $suggestion->suggested_category,
                            'suggested_status' => $candidate['status'] ?? $suggestion->suggested_status,
                            'matched_feature_id' => $featuresByKey->get($key)?->first()?->id,
                        ];
                    }
                    $suggestion->update($changes);
                    $updated++;

                    continue;
                }

                $project->featureSuggestions()->create([
                    'key' => $key,
                    'name' => $candidate['name'],
                    'description' => $candidate['description'] ?? null,
                    'suggested_category' => $candidate['category'] ?? null,
                    'suggested_status' => $candidate['status'] ?? null,
                    'status' => SuggestionStatus::Pending,
                    'matched_feature_id' => $featuresByKey->get($key)?->first()?->id,
                    'evidence' => $candidate['evidence'],
                    'first_scan_id' => $scan->id,
                    'last_scan_id' => $scan->id,
                ]);
                $new++;
            }

            $scan->update(['candidates_count' => $candidates->count(), 'new_count' => $new, 'updated_count' => $updated]);

            return $scan;
        });
    }
}
