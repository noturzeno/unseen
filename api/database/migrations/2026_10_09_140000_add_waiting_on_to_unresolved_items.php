<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who an open item waits on (client | team | agent; null = not sorted yet) and what is needed.
        Schema::table('unresolved_items', function (Blueprint $table) {
            $table->string('waiting_on', 16)->nullable()->after('status');
            $table->text('decision_note')->nullable()->after('waiting_on');
        });
    }

    public function down(): void
    {
        Schema::table('unresolved_items', function (Blueprint $table) {
            $table->dropColumn(['waiting_on', 'decision_note']);
        });
    }
};
