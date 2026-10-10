<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How a feature works, as Mermaid diagrams (flowchart, use case, sequence). Documentation, not proof.
        Schema::create('feature_diagrams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // DiagramType
            $table->string('title');
            $table->text('definition'); // Mermaid source
            $table->string('source', 30); // ClaimSource: who wrote it
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['feature_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_diagrams');
    }
};
