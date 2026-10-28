<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products of a project: sales targets, prices, collection policy, inventory and the calculated statements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('collection_policy_type')->nullable();
            $table->json('collection_policy_value')->nullable();
            $table->json('collection_statement')->nullable();
            $table->json('export_collection_statement')->nullable();
            $table->json('local_collection_statement')->nullable();
            $table->string('collection_policy_interval')->nullable();
            $table->string('name');
            $table->integer('selling_start_date')->default(0);
            $table->json('max_capacity')->nullable();
            $table->json('target_percentages')->nullable();
            $table->json('target_quantities')->nullable();
            $table->json('local_target_quantities')->nullable();
            $table->json('local_growth_rates')->nullable();
            $table->json('local_price_per_unit')->nullable();
            $table->decimal('sensitivity_price_rate', 14, 2)->nullable();
            $table->json('sales_target_values')->nullable();
            $table->string('fg_inventory_value')->nullable();
            $table->json('fg_beginning_inventory_breakdowns')->nullable();
            $table->json('product_inventory_value_statement')->nullable();
            $table->json('product_overheads_statement')->nullable();
            $table->json('raw_material_statement')->nullable();
            $table->json('product_overheads_allocation')->nullable();
            $table->json('product_depreciation_allocation')->nullable();
            $table->json('product_depreciation_statement')->nullable();
            $table->json('product_manpower_statement')->nullable();
            $table->json('product_manpower_allocation')->nullable();
            $table->json('product_inventory_qt_statement')->nullable();
            $table->decimal('fg_inventory_quantity', 14, 5)->default(0.00000);
            $table->string('fg_inventory_coverage_days')->nullable();
            $table->longText('comment_for_sales')->nullable();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();
            $table->string('measurement_unit')->nullable();
            $table->decimal('vat_rate', 14, 4)->default(0.0000);
            $table->decimal('withhold_tax_rate', 14, 4)->default(0.0000);
            $table->json('monthly_sales_target_values')->nullable();
            $table->json('monthly_sales_target_quantities')->nullable();
            $table->json('sensitivity_monthly_sales_target_quantities')->nullable();
            $table->json('sensitivity_monthly_sales_target_values')->nullable();
            $table->json('product_raw_material_consumed')->nullable();
            $table->json('local_target_quantity_percentages')->nullable();
            $table->json('export_target_quantity_percentages')->nullable();
            $table->json('export_growth_rates')->nullable();
            $table->json('export_price_per_unit')->nullable();
            $table->json('export_target_quantities')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
