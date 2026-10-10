<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the coding agent was asked to do, in a sentence. Feeds "Recently targeted modules".
        Schema::table('development_reports', function (Blueprint $table) {
            $table->text('prompt')->nullable()->after('agent');
        });
    }

    public function down(): void
    {
        Schema::table('development_reports', fn (Blueprint $table) => $table->dropColumn('prompt'));
    }
};
