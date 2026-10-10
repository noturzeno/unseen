<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A piece of evidence about a feature, with its source. For now each entry links
        // a development report to a feature it affected; scans and test runs come later.
        Schema::create('feature_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->foreignId('development_report_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 32); // development_report
            $table->string('source', 32); // ClaimSource
            $table->string('claimed_status', 32)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['feature_id', 'development_report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_evidence');
    }
};
