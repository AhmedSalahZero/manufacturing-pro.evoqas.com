<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening balance: fixed assets already owned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('gross_amount', 14, 2);
            $table->decimal('accumulated_depreciation', 14, 2)->default(0.00);
            $table->integer('monthly_counts')->default(0);
            $table->decimal('admin_depreciation_percentage', 14, 2)->default(0.00);
            $table->decimal('manufacturing_depreciation_percentage', 14, 2)->default(0.00);
            $table->json('product_allocations')->nullable();
            $table->json('monthly_product_allocations')->nullable();
            $table->unsignedBigInteger('is_as_revenue_percentages')->default(1);
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();
            $table->decimal('monthly_depreciation', 8, 2)->default(0.00);
            $table->json('admin_depreciations')->nullable();
            $table->json('statement')->nullable();
            $table->json('manufacturing_depreciations')->nullable();
            $table->json('monthly_accumulated_depreciations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_opening_balances');
    }
};
