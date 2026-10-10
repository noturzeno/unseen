<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The project's own roadmap (phases and their implementation lists), synced by its coding agent.
        Schema::create('roadmap_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100); // the agent's stable id, e.g. "M3" or "phase-2"
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20); // planned | in_progress | done | blocked
            $table->date('target_date')->nullable();
            $table->unsignedInteger('position');
            $table->string('source', 30); // ClaimSource
            $table->timestamps();

            $table->unique(['project_id', 'key']);
        });

        Schema::create('roadmap_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_phase_id')->constrained()->cascadeOnDelete();
            $table->string('text', 1000);
            $table->boolean('done')->default(false);
            $table->foreignId('feature_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('roadmap_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('roadmap_synced_at'));
        Schema::dropIfExists('roadmap_items');
        Schema::dropIfExists('roadmap_phases');
    }
};
