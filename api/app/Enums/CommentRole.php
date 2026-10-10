<?php

namespace App\Enums;

/**
 * Who a ticket comment (or a ticket) came from. The coding agent records QA and client
 * feedback that people pass on to it, so the role says whose words they are.
 */
enum CommentRole: string
{
    case Qa = 'qa';
    case Client = 'client';
    case Developer = 'developer';
    case ProjectManager = 'project_manager';
    case Agent = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::Qa => 'QA',
            self::Client => 'Client',
            self::Developer => 'Developer',
            self::ProjectManager => 'Project manager',
            self::Agent => 'Coding agent',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Qa => 'info',
            self::Client => 'warn',
            default => '',
        };
    }

    /** Feedback from outside the build: shown on the dashboard. */
    public static function feedback(): array
    {
        return [self::Qa, self::Client];
    }
}
