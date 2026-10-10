<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit trail of status / verification_status changes.
        Schema::create('feature_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->string('field', 32); // status | verification_status
            $table->string('from_value', 32)->nullable();
            $table->string('to_value', 32);
            $table->string('source', 32);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['feature_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_status_history');
    }
};
