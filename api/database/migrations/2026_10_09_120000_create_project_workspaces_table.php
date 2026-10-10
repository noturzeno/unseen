<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each person's own checkout of the project: where it is on their computer, and their runner.
        // Replaces the single folder/runner on projects, which only worked for one person.
        Schema::create('project_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('local_path', 1024)->nullable();
            $table->timestamp('runner_seen_at')->nullable();
            $table->string('runner_name', 255)->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });

        // The old values belonged to whoever set them up: the owner.
        DB::table('projects')->where(fn ($q) => $q->whereNotNull('local_path')->orWhereNotNull('runner_seen_at'))
            ->orderBy('id')->each(function ($project) {
                DB::table('project_workspaces')->insert([
                    'project_id' => $project->id,
                    'user_id' => $project->owner_id,
                    'local_path' => $project->local_path,
                    'runner_seen_at' => $project->runner_seen_at,
                    'runner_name' => $project->runner_name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn(['local_path', 'runner_seen_at', 'runner_name']));
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('local_path', 1024)->nullable();
            $table->timestamp('runner_seen_at')->nullable();
            $table->string('runner_name', 255)->nullable();
        });
        Schema::dropIfExists('project_workspaces');
    }
};
