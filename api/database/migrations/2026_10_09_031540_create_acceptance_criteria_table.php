<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_acceptance_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->unsignedInteger('position')->default(0);
            // Lets later milestones avoid overwriting user-entered criteria with agent guesses.
            $table->string('source', 32)->default('user_entered');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_acceptance_criteria');
    }
};
