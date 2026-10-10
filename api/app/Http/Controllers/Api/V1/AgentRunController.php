<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Models\Project;
use App\Services\AgentRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Used by `unseen runner` on the developer's computer: claim the token owner's next "Proceed" request for a project
 * (each call also counts as a check-in), then report whether Claude Code was opened.
 */
class AgentRunController extends Controller
{
    public function __construct(private readonly AgentRunService $runs) {}

    public function claim(Request $request, Project $project): JsonResponse|Response
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'runner' => ['required', 'string', 'max:100'],
            // The linked repository the runner was started in: becomes the project folder if none is set.
            'folder' => ['nullable', 'string', 'max:1024', 'regex:#^(/|[A-Za-z]:[\\\\/])[^\r\n\x00]*$#'],
        ]);
        $run = $this->runs->claim($project, $request->user(), $data['runner'], $data['folder'] ?? null);

        if (! $run) {
            return response()->noContent();
        }

        return response()->json(['data' => [
            'id' => $run->id,
            'project_id' => $project->id,
            'kind' => $run->kind,
            'title' => $run->title,
            'prompt' => $run->prompt,
            // Where to open it: this person's folder. The runner only uses it if it is linked to this same project.
            'local_path' => $project->workspaceFor($request->user())->local_path,
            'requested_by' => $run->user?->name,
            'created_at' => $run->created_at,
        ]]);
    }

    public function update(Request $request, AgentRun $run): JsonResponse
    {
        Gate::authorize('update', $run->project);
        abort_unless($run->status === AgentRun::CLAIMED, 409, 'This run is not waiting for a result.');

        $data = $request->validate([
            'status' => ['required', Rule::in([AgentRun::LAUNCHED, AgentRun::FAILED])],
            'error' => ['nullable', 'string', 'max:2000'],
        ]);

        $run->update([...$data, 'finished_at' => now()]);

        return response()->json(['data' => ['id' => $run->id, 'status' => $run->status]]);
    }
}
