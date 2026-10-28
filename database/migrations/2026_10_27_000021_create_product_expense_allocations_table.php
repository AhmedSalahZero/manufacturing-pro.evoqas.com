<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How overheads, depreciation and manpower are split between products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_expense_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('expense_type')->nullable();
            $table->string('expense_category')->nullable();
            $table->string('expense_name')->nullable();
            $table->boolean('is_expense')->default(1);
            $table->boolean('is_depreciation')->default(0);
            $table->boolean('is_opening_depreciation')->default(0);
            $table->json('payload')->nullable();
            $table->json('sensitivity_payload')->nullable();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();
            $table->boolean('is_indirect_manpower')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_expense_allocations');
    }
};
