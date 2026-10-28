<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * All expense lines (fixed, per unit, % of revenue, one-time) with their calculated monthly amounts, VAT and withholding tax.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('amortization_months')->nullable()->default(12);
            $table->string('name');
            $table->string('category_id')->nullable();
            $table->integer('start_date')->nullable();
            $table->integer('end_date')->nullable()->comment('end date as index');
            $table->string('monthly_cost_of_unit')->nullable();
            $table->string('percentage_of')->nullable();
            $table->string('monthly_percentage')->nullable()->default('0');
            $table->json('products')->nullable();
            $table->string('payment_terms')->nullable();
            $table->json('payment_amounts')->nullable();
            $table->json('collection_statements');
            $table->string('vat_rate')->nullable();
            $table->integer('is_deductible')->default(0);
            $table->string('withhold_tax_rate')->nullable();
            $table->string('increase_rate')->nullable();
            $table->string('increase_interval')->nullable();
            $table->decimal('amount', 14, 0)->default(0);
            $table->longText('monthly_repeating_amounts')->nullable();
            $table->json('withhold_amounts')->nullable();
            $table->json('vat_amounts')->nullable();
            $table->longText('expense_as_percentages')->nullable();
            $table->json('total_vat')->nullable();
            $table->json('total_after_vat')->nullable();
            $table->json('withhold_payments')->nullable();
            $table->json('net_payments_after_withhold')->nullable();
            $table->longText('sensitivity_expense_as_percentages')->nullable();
            $table->longText('payload')->nullable();
            $table->string('expense_type')->nullable();
            $table->string('relation_name')->nullable();
            $table->longText('custom_collection_policy')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->json('product_revenues')->nullable();
            $table->json('product_allocations')->nullable();
            $table->json('monthly_product_allocations')->nullable();
            $table->unsignedBigInteger('is_as_revenue_percentages')->default(1);
            $table->json('prepaid_expense_statement')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
