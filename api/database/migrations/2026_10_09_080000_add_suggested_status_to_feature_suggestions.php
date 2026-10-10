<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the project's own docs say about the feature (e.g. a "Partial" row in an implementation
        // matrix). Used as the default status when the suggestion is accepted; never as verification.
        Schema::table('feature_suggestions', function (Blueprint $table) {
            $table->string('suggested_status', 20)->nullable()->after('suggested_category');
        });
    }

    public function down(): void
    {
        Schema::table('feature_suggestions', fn (Blueprint $table) => $table->dropColumn('suggested_status'));
    }
};
