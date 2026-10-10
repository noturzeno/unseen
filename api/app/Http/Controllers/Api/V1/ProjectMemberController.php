<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Owners add members (or invite people without an account) and remove them; any member may leave.
 */
class ProjectMemberController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json([
            'data' => [
                'owner' => new UserResource($project->owner),
                'members' => UserResource::collection($project->members()->orderBy('name')->get()),
            ],
        ]);
    }

    public function store(Request $request, Project $project, ProjectMemberService $members): JsonResponse
    {
        Gate::authorize('manageMembers', $project);

        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $result = $members->addOrInvite($project, $data['email'], $request->user());

        if (isset($result['user'])) {
            return response()->json(['data' => new UserResource($result['user'])], 201);
        }

        // No account with that email yet: they were invited (emailed, and the link is returned once).
        return response()->json(['data' => [
            'invitation' => [
                'email' => $result['invitation']->email,
                'url' => $result['url'],
                'expires_at' => $result['invitation']->expires_at,
            ],
        ]], 202);
    }

    public function destroy(Request $request, Project $project, User $user, ProjectMemberService $members): Response
    {
        if (! $request->user()->is($user)) {
            Gate::authorize('manageMembers', $project);
        }
        abort_unless($project->hasMember($user), 404);

        $members->remove($project, $user);

        return response()->noContent();
    }
}
