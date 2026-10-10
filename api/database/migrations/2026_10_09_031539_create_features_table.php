<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('feature_categories')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('planned');
            $table->string('verification_status', 32)->default('not_checked');
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
