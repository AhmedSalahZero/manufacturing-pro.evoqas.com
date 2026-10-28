<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening balance: VAT and withholding-tax credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_and_credit_withhold_tax_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->decimal('vat_amount', 14, 2)->default(0.00);
            $table->decimal('credit_withhold_taxes', 14, 2)->default(0.00);
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();
            $table->decimal('corporate_taxes_payable', 14, 2)->default(0.00);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_and_credit_withhold_tax_opening_balances');
    }
};
