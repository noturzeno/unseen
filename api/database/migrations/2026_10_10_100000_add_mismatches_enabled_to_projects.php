<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PRD vs design can be turned off per project. Off hides it everywhere; recorded mismatches are kept.
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('mismatches_enabled')->default(true)->after('platforms');
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('mismatches_enabled'));
    }
};
