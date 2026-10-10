<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The Unseen CLI this server hands out (public/downloads/unseen-cli.tgz), and who needs to update to it.
 * Keep LATEST equal to packages/cli/package.json when repacking the CLI (a test checks this).
 */
final class Cli
{
    public const LATEST = '0.3.0';

    /** Only people whose CLI was seen this recently are asked to update. */
    public const SEEN_WITHIN_DAYS = 30;

    /** "unseen-cli/0.2.0" → "0.2.0"; null for anything else. */
    public static function versionFromUserAgent(?string $userAgent): ?string
    {
        return preg_match('~^unseen-cli/(\d+\.\d+\.\d+[\w.+-]*)~', (string) $userAgent, $m) ? $m[1] : null;
    }

    public static function isOutdated(?string $version): bool
    {
        return $version !== null && version_compare($version, self::LATEST, '<');
    }

    /**
     * People on the project (owner and members) whose CLI is older than LATEST, the viewer first.
     *
     * @return Collection<int, User>
     */
    public static function outdated(Project $project, ?User $viewer = null): Collection
    {
        return collect([$project->owner, ...$project->members()->get()])
            ->filter(fn (User $u) => self::isOutdated($u->cli_version) && $u->cli_seen_at?->gt(now()->subDays(self::SEEN_WITHIN_DAYS)))
            ->unique('id')
            ->sortBy(fn (User $u) => [$viewer && $u->is($viewer) ? 0 : 1, $u->name])
            ->values();
    }

    public static function installCommand(): string
    {
        return 'npm install -g '.rtrim((string) config('app.url'), '/').'/downloads/unseen-cli.tgz';
    }

    /** Prompt for the coding agent: reinstall the CLI from this server and check it. */
    public static function updatePrompt(): string
    {
        return view('agent.cli-update-prompt', ['command' => self::installCommand(), 'latest' => self::LATEST])->render();
    }
}
