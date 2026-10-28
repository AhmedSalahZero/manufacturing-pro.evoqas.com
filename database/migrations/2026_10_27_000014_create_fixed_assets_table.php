<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed assets: cost, depreciation, funding by equity and loan, and calculated statements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('counts');
            $table->decimal('amount', 14, 2)->default(0.00);
            $table->integer('depreciation_duration');
            $table->integer('start_date');
            $table->integer('end_date');
            $table->string('payment_terms');
            $table->json('custom_collection_policy')->nullable();
            $table->json('from_total_or_executions')->nullable();
            $table->decimal('admin_depreciation_percentage', 14, 2)->default(0.00);
            $table->decimal('manufacturing_depreciation_percentage', 14, 2)->default(0.00);
            $table->decimal('equity_funding_rate', 14, 2)->default(0.00);
            $table->decimal('interest_rate', 14, 2)->default(0.00);
            $table->integer('tenor');
            $table->string('installment_interval');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->json('product_allocations')->nullable();
            $table->json('monthly_product_allocations')->nullable();
            $table->unsignedBigInteger('is_as_revenue_percentages')->default(1);
            $table->timestamps();
            $table->decimal('replacement_cost_rate', 14, 2)->default(0.00);
            $table->integer('replacement_cost_interval')->default(0);
            $table->integer('grace_period')->nullable()->default(0);
            $table->json('depreciation_statement')->nullable();
            $table->json('capitalization_statement')->nullable();
            $table->json('admin_depreciations')->nullable();
            $table->json('ffe_equity_payment')->nullable();
            $table->json('ffe_loan_withdrawal')->nullable();
            $table->json('income_statement_loan_capitalized_interests')->nullable();
            $table->json('loan_capitalized_interests')->nullable();
            $table->json('ffe_loan_withdrawal_end_balance')->nullable();
            $table->json('ffe_payment')->nullable();
            $table->json('statement')->nullable();
            $table->json('ffe_execution_and_payment')->nullable();
            $table->json('ffe_payable')->nullable();
            $table->decimal('reservation_rate', 14, 2)->default(0.00);
            $table->decimal('contractual_rate', 14, 2)->default(0.00);
            $table->integer('after_months')->default(0);
            $table->decimal('installment_grace_period', 14, 2)->default(0.00);
            $table->integer('installment_count')->default(0);
            $table->string('payment_installment_interval')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
