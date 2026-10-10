<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Structured record of work done by a coding agent (or entered manually). Immutable once stored.
        Schema::create('development_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 32); // ClaimSource
            $table->string('agent', 100)->nullable(); // e.g. "claude-code"
            $table->text('summary');
            $table->string('confidence', 16)->nullable();
            $table->text('limitations')->nullable();
            $table->string('git_branch')->nullable();
            $table->string('git_commit', 64)->nullable();
            $table->string('diff_ref', 2048)->nullable();
            // Flexible list payloads; queryable facts live in columns above and in feature_evidence.
            $table->json('changed_files')->nullable();
            $table->json('tests')->nullable();
            $table->json('unresolved')->nullable();
            $table->unsignedInteger('tests_failed_count')->default(0);
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->index(['project_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('development_reports');
    }
};
