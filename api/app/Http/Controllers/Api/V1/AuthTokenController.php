<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Issues Sanctum personal access tokens for API clients (e.g. the Unseen CLI).
 *
 * Abilities (see routes/api.php):
 *  - read:   view projects, features, categories, reports, dashboard
 *  - report: submit development reports
 *  - record: tracking data: features, categories, criteria, statuses, checks, diagrams,
 *            suggestion reviews, closing unresolved items (never verification)
 *  - write:  everything `record` allows, plus projects, members and token management
 *  - verify: submit automated test runs, which can mark features verified or failed
 * Tokens get all of them unless `abilities` limits them. Coding agents (and the CLI) get
 * read + report + record; CI should get read + verify.
 */
class AuthTokenController extends Controller
{
    public const ABILITIES = ['read', 'report', 'record', 'write', 'verify'];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'token_name' => ['required', 'string', 'max:255'],
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(self::ABILITIES)],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $abilities = array_values(array_unique($validated['abilities'] ?? self::ABILITIES));
        $expiresAt = isset($validated['expires_in_days']) ? now()->addDays($validated['expires_in_days']) : null;
        $token = $user->createToken($validated['token_name'], $abilities, $expiresAt);

        // The plain-text token is only ever returned here; store it outside source control.
        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
            'user' => new UserResource($user),
        ], 201);
    }

    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * The signed-in user's tokens (never the token values).
     */
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->id ?? null;

        return response()->json(['data' => $request->user()->tokens()->latest()->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'abilities' => $t->abilities,
            'last_used_at' => $t->last_used_at,
            'expires_at' => $t->expires_at,
            'created_at' => $t->created_at,
            'current' => $t->id === $currentId,
        ])]);
    }

    public function revoke(Request $request, int $token): Response
    {
        $deleted = $request->user()->tokens()->whereKey($token)->delete();
        abort_unless($deleted, 404);

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
