<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors `PhoneResource` — `{type, value}` only.
     *
     * `normalized_value` holds the last 10 digits (US); E.164 is the production
     * form. Backs the exact-phone blocking self-join.
     */
    public function up(): void
    {
        Schema::create('phones', function (Blueprint $table) {
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
        Schema::dropIfExists('phones');
    }
};
