<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Flagged in the project settings when GitHub Actions minutes run out: the dashboard then
        // suggests running CI on a local Mac. Each person can dismiss that; flagging it again shows it again.
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('actions_minutes_out_at')->nullable()->after('mismatches_enabled');
        });
        Schema::table('project_workspaces', function (Blueprint $table) {
            $table->timestamp('ci_notice_dismissed_at')->nullable()->after('runner_name');
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('actions_minutes_out_at'));
        Schema::table('project_workspaces', fn (Blueprint $table) => $table->dropColumn('ci_notice_dismissed_at'));
    }
};
