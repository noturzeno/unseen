<?php

namespace App\Http\Middleware;

use App\Support\Cli;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers which Unseen CLI version a person's API requests come from, so the dashboard can ask
 * them to update. Writes only when the version changes or at most hourly.
 */
class RecordCliVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        $version = Cli::versionFromUserAgent($request->userAgent());
        if ($user && $version && ($user->cli_version !== $version || ! $user->cli_seen_at?->gt(now()->subHour()))) {
            $user->forceFill(['cli_version' => $version, 'cli_seen_at' => now()])->saveQuietly();
        }

        return $response;
    }
}
