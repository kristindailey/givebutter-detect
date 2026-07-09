<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Source of truth for the three derived contact fields. `captured_at` is the
     * settlement timestamp that drives `contact_since` — not `created_at`.
     *
     * `status` is a bare string in Givebutter's OpenAPI spec with no documented
     * enum; the recompute filter assumes the value `succeeded`. String PK mirrors
     * `TransactionResource.id`.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('succeeded');
            $table->string('payment_method')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            // Covers the recompute scan: all succeeded rows for a contact, by date.
            $table->index(['contact_id', 'status', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
