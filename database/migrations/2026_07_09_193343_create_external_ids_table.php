<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors `ExternalIdResource`. Mirrored but never matched on — external-ID
     * matching is the deliberate weekend cut line. The merge unions these.
     */
    public function up(): void
    {
        Schema::create('external_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('external_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_ids');
    }
};
