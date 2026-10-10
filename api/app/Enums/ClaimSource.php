<?php

namespace App\Enums;

/**
 * Where a claim (status change, criterion, evidence) came from.
 */
enum ClaimSource: string
{
    case UserEntered = 'user_entered';
    case AgentReported = 'agent_reported';
    case RepositoryDetected = 'repository_detected';
    case TestVerified = 'test_verified';

    public function label(): string
    {
        return match ($this) {
            self::UserEntered => 'Entered by a user',
            self::AgentReported => 'Reported by an agent',
            self::RepositoryDetected => 'Detected in the repository',
            self::TestVerified => 'Confirmed by tests',
        };
    }
}
