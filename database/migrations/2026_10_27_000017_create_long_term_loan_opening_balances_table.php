<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening balance: long-term loans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('long_term_loan_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 14, 2)->default(0.00);
            $table->json('interests')->nullable();
            $table->json('installments')->nullable();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();
            $table->json('statement')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('long_term_loan_opening_balances');
    }
};
