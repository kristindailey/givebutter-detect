<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Co-membership join. The same-household block generates the Jennifer/Jen
     * hero pair, whose names and emails are too weak to block on reliably.
     */
    public function up(): void
    {
        Schema::create('household_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            $table->unique(['household_id', 'contact_id']);
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_contacts');
    }
};
