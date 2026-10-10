<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One run of the local repository scanner. The code itself never reaches the server,
        // only the evidence the scanner found (paths, route URIs, class names).
        Schema::create('repository_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scanner', 100)->nullable();
            $table->string('git_branch')->nullable();
            $table->string('git_commit', 64)->nullable();
            $table->unsignedInteger('candidates_count')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_scans');
    }
};
