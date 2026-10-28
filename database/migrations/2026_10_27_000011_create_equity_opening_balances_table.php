<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening balance: equity / paid-up capital.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equity_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->decimal('paid_up_capital_amount', 14, 2)->default(0.00);
            $table->json('legal_reserve_extended')->nullable();
            $table->json('paid_up_capital_extended')->nullable();
            $table->decimal('legal_reserve', 14, 2)->default(0.00);
            $table->decimal('retained_earnings', 14, 2)->default(0.00);
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();
            $table->json('statement')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equity_opening_balances');
    }
};
