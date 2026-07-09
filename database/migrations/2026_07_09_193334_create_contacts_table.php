<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors Givebutter's `ContactResource` (docs.givebutter.com). Three column
     * groups sit on top of the mirrored API shape:
     * - derived: recomputed from transactions on merge, never free-text
     * - blocking keys: normalized values the detection blocks index
     * - archived_at: the reversible soft-delete a merge loser receives
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->nullable();
            $table->string('type')->default('individual');

            $table->string('prefix')->nullable();
            $table->string('first_name')->nullable();
            $table->string('preferred_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('suffix')->nullable();
            $table->date('dob')->nullable();

            $table->string('company')->nullable();
            $table->string('title')->nullable();

            $table->string('primary_email')->nullable();
            $table->string('primary_phone')->nullable();

            // Derived — recomputed from transactions. `last_donation_amount` is a
            // string to mirror the API's string money fields.
            $table->decimal('total_contributions', 12, 2)->default(0);
            $table->date('contact_since')->nullable();
            $table->string('last_donation_amount')->nullable();

            // Blocking keys — written by the Normalizer during seeding and detect:run.
            // `address_key` derives from the contact's primary address, keeping both
            // keys on this table rather than indexing the child `addresses`.
            $table->text('name_key')->nullable();
            $table->text('address_key')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
