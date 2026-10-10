<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;

/**
 * "Connect a coding agent": issues a coding-agent token limited to one project and builds the
 * prompt a person pastes into their coding agent, which then installs the CLI, links the
 * repository and keeps Unseen up to date on its own.
 */
final class AgentSetup
{
    public const TOKEN_DAYS = 90;

    /**
     * Where the project is: just started (record the plan), already developed (inventory the code),
     * or already tracked (join: link another contributor's agent without importing anything again).
     */
    public const STAGES = ['new', 'existing', 'join'];

    /**
     * @return array{token: string, prompt: string}
     */
    public static function issue(Project $project, User $user, string $stage = 'existing'): array
    {
        if (! in_array($stage, self::STAGES, true)) {
            throw new \InvalidArgumentException("Unknown project stage [{$stage}].");
        }

        $token = $user->createToken(
            'Coding agent · '.mb_strimwidth($project->name, 0, 200, '…'),
            [...TokenPresets::abilities('agent'), 'project:'.$project->id],
            now()->addDays(self::TOKEN_DAYS),
        )->plainTextToken;

        return ['token' => $token, 'prompt' => self::prompt($project, $token, $stage)];
    }

    public static function prompt(Project $project, string $token, string $stage = 'existing'): string
    {
        $url = rtrim((string) config('app.url'), '/');
        // Plain text, not HTML. The name is user data: keep it to one line so it can't add instructions.
        $name = str_replace(['"', "\r", "\n"], ["'", ' ', ' '], $project->name);

        return view('agent.setup-prompt', [
            'project' => $project,
            'featuresCount' => $project->features()->count(),
            'name' => $name,
            'stage' => $stage,
            'token' => $token,
            'url' => $url,
            'claudeMd' => view('agent.claude-md', ['project' => $project, 'url' => $url, 'name' => $name])->render(),
        ])->render();
    }
}
