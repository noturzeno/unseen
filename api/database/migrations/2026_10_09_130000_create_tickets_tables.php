<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Work tickets (feature, bug, change request) and where they are in the build → QA → client flow.
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 64)->nullable(); // the ticket's id elsewhere, e.g. "WEB-142"
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 32)->default('task'); // TicketType
            $table->string('status', 32)->default('open'); // TicketStatus
            $table->string('priority', 16)->nullable(); // low | medium | high | urgent
            $table->string('raised_by', 32)->nullable(); // CommentRole
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 32); // ClaimSource
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'reference']);
            $table->index(['project_id', 'status']);
        });

        // A ticket's thread: QA, client and team comments, and status changes (with their note).
        Schema::create('ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('author_role', 32); // CommentRole: whose words these are
            $table->string('author_name', 100)->nullable();
            $table->text('body')->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->foreignId('development_report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who recorded it
            $table->string('source', 32); // ClaimSource
            $table->timestamps();

            $table->index(['project_id', 'author_role', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_comments');
        Schema::dropIfExists('tickets');
    }
};
