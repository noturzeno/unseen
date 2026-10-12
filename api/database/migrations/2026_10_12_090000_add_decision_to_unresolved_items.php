<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The answer a person gave on the Unresolved page (decision_note is the question), who gave it and when.
        Schema::table('unresolved_items', function (Blueprint $table) {
            $table->text('decision')->nullable()->after('decision_note');
            $table->foreignId('decided_by')->nullable()->after('decision')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
        });
    }

    public function down(): void
    {
        Schema::table('unresolved_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['decision', 'decided_at']);
        });
    }
};
