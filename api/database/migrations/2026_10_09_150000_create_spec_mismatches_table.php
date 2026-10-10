<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Places where the PRD and the designer's design disagree, and what a person decided.
        Schema::create('spec_mismatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key', 100)->nullable(); // stable id from the agent, so re-recording updates instead of duplicating
            $table->string('reference', 255)->nullable(); // e.g. "PRD §4.11 PRM-01 · Figma Checkout v3"
            $table->string('title');
            $table->text('prd_says');
            $table->text('design_says');
            $table->string('built_to', 16)->default('design'); // BuiltTo
            $table->text('built_note')->nullable(); // what the code does now
            $table->text('impact')->nullable(); // why it matters
            $table->string('decide_by', 16)->default('team'); // WaitingOn: team | client
            $table->string('status', 16)->default('open'); // MismatchStatus
            $table->string('decision', 16)->nullable(); // MismatchDecision
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('applied_note')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who recorded it
            $table->string('source', 32); // ClaimSource
            $table->timestamps();

            $table->unique(['project_id', 'key']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spec_mismatches');
    }
};
