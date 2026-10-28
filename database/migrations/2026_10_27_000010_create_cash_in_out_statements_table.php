<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SAVED RESULT: cash-in / cash-out statement of a project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_in_out_statements', function (Blueprint $table) {
            $table->id();
            $table->json('cash_end_balance')->nullable();
            $table->json('working_capital_injection')->nullable();
            $table->json('equity_injection')->nullable();
            $table->json('loan_withdrawal')->nullable();
            $table->json('customer_collection')->nullable();
            $table->json('supplier_payments')->nullable();
            $table->json('taxes')->nullable();
            $table->json('expenses')->nullable();
            $table->json('fixed_asset_payments')->nullable();
            $table->json('loan_installments')->nullable();
            $table->json('total_cash_out')->nullable();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_in_out_statements');
    }
};
