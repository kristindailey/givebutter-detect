<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prototype-only. Precomputed by `detect:run`; the Review Queue reads the
     * pending rows rather than scoring on page load.
     *
     * Pairs are canonically ordered (`contact_a_id < contact_b_id`) by the writer;
     * the unique index only guards against duplicate rows. Candidates are strictly
     * pairwise — no transitive clustering.
     */
    public function up(): void
    {
        Schema::create('duplicate_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_a_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('contact_b_id')->constrained('contacts')->cascadeOnDelete();
            $table->decimal('score', 5, 2);

            // Per-signal contribution + matched values, so the UI renders "why"
            // without recomputing.
            $table->jsonb('signal_breakdown');

            // Queue state. A dismissal is a labeled negative — the same confirmed
            // history that would train the scoring weights in production.
            $table->string('resolution')->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->unique(['contact_a_id', 'contact_b_id']);
            $table->index(['resolution', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_candidates');
    }
};
