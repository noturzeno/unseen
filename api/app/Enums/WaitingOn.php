<?php

namespace App\Enums;

/**
 * Who an open unresolved item is waiting on. Set by the coding agent when it leaves work open
 * (or when it sorts existing items). Not set means nobody has sorted it yet.
 */
enum WaitingOn: string
{
    case Client = 'client';
    case Team = 'team';
    case Agent = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Client decision needed',
            self::Team => 'Your decision needed',
            self::Agent => 'Agent can do it',
        };
    }

    /** Heading for the group of items. */
    public function heading(): string
    {
        return match ($this) {
            self::Client => 'Waiting on the client',
            self::Team => 'Waiting on you',
            self::Agent => 'Your coding agent can do these',
        };
    }

    public function explanation(): string
    {
        return match ($this) {
            self::Client => 'The client has to decide, confirm or provide something. Ask them, then pass the answer to your agent.',
            self::Team => 'You or your team have to decide, approve or provide access. Your agent will not guess these.',
            self::Agent => 'No decision needed: hand these to your coding agent.',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Client => 'warn',
            self::Team => 'bad',
            self::Agent => 'info',
        };
    }

    /** A person has to act before the agent can. */
    public function needsDecision(): bool
    {
        return $this !== self::Agent;
    }
}
