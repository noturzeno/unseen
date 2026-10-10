<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which automated tests prove a feature works. Set by a person, matched against test run cases.
        Schema::create('feature_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->string('pattern', 500); // "Tests\Feature\InvoiceTest", "InvoiceTest::test_*", "tests/invoice.test.ts"
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['feature_id', 'pattern']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_checks');
    }
};
