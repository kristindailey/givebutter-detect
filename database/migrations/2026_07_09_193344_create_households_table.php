<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors `HouseholdResource`. `head_contact_id` is the only role marker the
     * real schema carries — there is no per-member relationship label, so the
     * household modifier keys off co-membership plus head designation alone.
     */
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('head_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('envelope_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('households');
    }
};
