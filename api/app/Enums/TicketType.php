<?php

namespace App\Enums;

enum TicketType: string
{
    case Feature = 'feature';
    case Bug = 'bug';
    case ChangeRequest = 'change_request';
    case Task = 'task';

    public function label(): string
    {
        return match ($this) {
            self::Feature => 'Feature',
            self::Bug => 'Bug',
            self::ChangeRequest => 'Change request',
            self::Task => 'Task',
        };
    }
}
