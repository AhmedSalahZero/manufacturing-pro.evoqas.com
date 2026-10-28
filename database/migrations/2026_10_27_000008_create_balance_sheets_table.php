<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SAVED RESULT: balance sheet and working-capital series of a project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_sheets', function (Blueprint $table) {
            $table->id();
            $table->json('supplier_payables')->nullable();
            $table->json('other_debtors')->nullable();
            $table->json('raw_material_inventory')->nullable();
            $table->json('total_fgs')->nullable();
            $table->json('customer_receivables')->nullable();
            $table->json('cash_and_banks')->nullable();
            $table->json('total_current_liabilities')->nullable();
            $table->json('total_current_assets')->nullable();
            $table->json('change_in_customer_receivables')->nullable();
            $table->json('change_in_fg_inventory')->nullable();
            $table->json('change_in_raw_material_inventory')->nullable();
            $table->json('change_in_other_debtors')->nullable();
            $table->json('change_in_supplier_payables')->nullable();
            $table->json('change_in_other_creditors')->nullable();
            $table->json('net_change_in_working_capital')->nullable();
            $table->json('debit_funding_percentages')->nullable();
            $table->json('equity_funding_percentages')->nullable();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_sheets');
    }
};
