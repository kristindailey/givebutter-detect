<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors `EmailResource` — `{type, value}` only.
     *
     * `normalized_value` (lowercased + trimmed) backs the exact-email blocking
     * self-join; the btree index is what keeps that block O(matches).
     */
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('type')->nullable();
            $table->string('value');
            $table->string('normalized_value')->nullable();
            $table->timestamps();

            $table->index('normalized_value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
