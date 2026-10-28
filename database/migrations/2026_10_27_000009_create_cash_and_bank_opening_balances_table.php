<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening balance: cash and bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_and_bank_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->decimal('cash_and_bank_amount', 14, 2)->default(0.00);
            $table->decimal('customer_receivable_amount', 14, 2)->default(0.00);
            $table->decimal('inventory_amount', 14, 2)->default(0.00);
            $table->json('payload')->nullable();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();
            $table->json('statement')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_and_bank_opening_balances');
    }
};
