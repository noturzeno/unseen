<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_run_check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->string('outcome', 16); // passed | failed | missing
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('failures')->nullable(); // first few failing case ids + messages
            $table->timestamp('created_at')->useCurrent();

            $table->index(['feature_check_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_run_check_results');
    }
};
