<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where the project's repository lives on the developer's computer, and when a runner last checked in.
        Schema::table('projects', function (Blueprint $table) {
            $table->string('local_path', 1024)->nullable();
            $table->timestamp('runner_seen_at')->nullable();
            $table->string('runner_name', 255)->nullable();
        });

        // "Proceed" requests: a prompt for the project's coding agent, picked up by `unseen runner`
        // on the developer's computer, which opens Claude Code in the project folder.
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who clicked Proceed
            $table->string('kind', 30);
            $table->unsignedBigInteger('ref')->nullable();
            $table->string('title');
            $table->text('prompt');
            $table->string('status', 20); // queued | claimed | launched | failed | expired
            $table->string('runner_name')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn(['local_path', 'runner_seen_at', 'runner_name']));
    }
};
