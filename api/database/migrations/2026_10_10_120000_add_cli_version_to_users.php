<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Unseen CLI version this person's requests last came from (its User-Agent), for the update prompt.
        Schema::table('users', function (Blueprint $table) {
            $table->string('cli_version', 32)->nullable();
            $table->timestamp('cli_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['cli_version', 'cli_seen_at']));
    }
};
