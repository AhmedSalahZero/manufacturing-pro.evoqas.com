<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SAVED RESULT: income statement series of a project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_statements', function (Blueprint $table) {
            $table->id();
            $table->json('total_sales_revenues')->nullable();
            $table->json('annually_sales_revenues_growth_rates')->nullable();
            $table->json('gross_profit')->nullable();
            $table->json('annually_gross_profit_revenue_percentages')->nullable();
            $table->json('ebitda')->nullable();
            $table->json('annually_ebitda_revenue_percentages')->nullable();
            $table->json('ebit')->nullable();
            $table->json('annually_ebit_revenue_percentages')->nullable();
            $table->json('ebt')->nullable();
            $table->json('monthly_ebt')->nullable();
            $table->json('annually_ebt_revenue_percentages')->nullable();
            $table->json('net_profit')->nullable();
            $table->json('annually_net_profit')->nullable();
            $table->json('annually_net_profit_revenue_percentages')->nullable();
            $table->json('accumulated_retained_earnings')->nullable();
            $table->json('total_depreciation')->nullable();
            $table->json('total_cogs')->nullable();
            $table->json('total_percentages_cogs')->nullable();
            $table->json('sganda')->nullable();
            $table->json('sganda_revenues_percentages')->nullable();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_statements');
    }
};
