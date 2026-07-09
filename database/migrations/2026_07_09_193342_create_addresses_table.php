<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors `AddressResource`. The normalized address key used for trigram
     * blocking lives on `contacts.address_key` (derived from the primary
     * address), not here.
     */
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('address_1')->nullable();
            $table->string('address_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zipcode')->nullable();
            $table->string('country')->nullable();
            $table->string('type')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['contact_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
