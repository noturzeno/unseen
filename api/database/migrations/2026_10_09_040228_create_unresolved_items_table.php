<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Open work listed in development reports, tracked until someone closes it.
        Schema::create('unresolved_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('development_report_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->string('status', 16)->default('open'); // open | resolved
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolved_source', 32)->nullable(); // ClaimSource
            $table->foreignId('resolved_report_id')->nullable()->constrained('development_reports')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        // Backfill from reports stored before this table existed.
        DB::table('development_reports')->whereNotNull('unresolved')->orderBy('id')->each(function ($report) {
            foreach (json_decode($report->unresolved, true) ?: [] as $text) {
                DB::table('unresolved_items')->insert([
                    'project_id' => $report->project_id,
                    'development_report_id' => $report->id,
                    'text' => $text,
                    'status' => 'open',
                    'created_at' => $report->created_at,
                    'updated_at' => $report->created_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unresolved_items');
    }
};
