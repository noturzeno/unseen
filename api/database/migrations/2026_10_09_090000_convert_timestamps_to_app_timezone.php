<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The app timezone changed from UTC to config('app.timezone') (Asia/Bangkok). Timestamps are stored
 * without a zone, so rows written before this change hold UTC wall-clock times: shift them by the
 * zone's offset so they read correctly. Date-only and zone-aware columns are left alone.
 * A no-op when the app timezone is UTC, and on empty databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shift(+1);
    }

    public function down(): void
    {
        $this->shift(-1);
    }

    private function shift(int $direction): void
    {
        $seconds = $direction * (new DateTimeZone(config('app.timezone')))->getOffset(new DateTime('now', new DateTimeZone('UTC')));
        if ($seconds === 0) {
            return;
        }

        $driver = DB::getDriverName();
        $grammar = DB::getQueryGrammar();

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if ($name === 'migrations') {
                continue;
            }
            foreach (Schema::getColumns($name) as $column) {
                $type = strtolower($column['type_name'] ?? $column['type'] ?? '');
                if (! in_array($type, ['timestamp', 'datetime', 'timestamp without time zone'], true)) {
                    continue;
                }
                $col = $grammar->wrap($column['name']);
                $shifted = match ($driver) {
                    'pgsql' => "{$col} + interval '{$seconds} seconds'",
                    'sqlite' => "datetime({$col}, '".($seconds > 0 ? '+' : '')."{$seconds} seconds')",
                    default => "DATE_ADD({$col}, INTERVAL {$seconds} SECOND)",
                };
                DB::statement("update {$grammar->wrapTable($name)} set {$col} = {$shifted} where {$col} is not null");
            }
        }
    }
};
