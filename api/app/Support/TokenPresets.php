<?php

namespace App\Support;

/**
 * Named token types for the UI. Abilities are explained in AuthTokenController.
 */
final class TokenPresets
{
    public const PRESETS = [
        'agent' => [
            'label' => 'Coding agent (CLI)',
            'abilities' => ['read', 'report', 'record'],
            'hint' => 'For "unseen init" / Claude Code: records features, statuses, criteria, checks and diagrams, sends reports and scans. Cannot verify, or change projects, members or tokens.',
        ],
        'ci' => [
            'label' => 'CI test results',
            'abilities' => ['read', 'verify'],
            'hint' => 'For CI running "unseen verify": can mark features verified or failed from test runs.',
        ],
        'full' => [
            'label' => 'Full access',
            'abilities' => ['read', 'report', 'record', 'write', 'verify'],
            'hint' => 'Everything, including marking features verified from test runs. Use sparingly.',
        ],
    ];

    /** @return list<string> */
    public static function abilities(string $preset): array
    {
        return self::PRESETS[$preset]['abilities'];
    }
}
