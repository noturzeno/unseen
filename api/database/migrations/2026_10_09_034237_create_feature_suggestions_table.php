<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A candidate feature found by scanning. One row per (project, key), so rescans
        // update it instead of creating duplicates. Becomes a feature only after review.
        Schema::create('feature_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('suggested_category')->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('feature_id')->nullable()->constrained()->nullOnDelete(); // accepted/merged target
            $table->foreignId('matched_feature_id')->nullable()->constrained('features')->nullOnDelete(); // likely duplicate hint
            $table->json('evidence');
            $table->foreignId('first_scan_id')->nullable()->constrained('repository_scans')->nullOnDelete();
            $table->foreignId('last_scan_id')->nullable()->constrained('repository_scans')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'key']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_suggestions');
    }
};
