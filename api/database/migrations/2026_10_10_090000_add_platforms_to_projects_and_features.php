<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lists of App\Enums\Platform values. On a feature, null means every platform of the project.
        Schema::table('projects', function (Blueprint $table) {
            $table->json('platforms')->nullable()->after('tech_stack');
        });
        Schema::table('features', function (Blueprint $table) {
            $table->json('platforms')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('features', fn (Blueprint $table) => $table->dropColumn('platforms'));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('platforms'));
    }
};
