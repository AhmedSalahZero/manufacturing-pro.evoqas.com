<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw materials of a project: prices, payment terms, inventory and calculated statements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_materials', function (Blueprint $table) {
            $table->id();
            $table->json('collection_policy_value')->nullable();
            $table->json('collection_statement')->nullable();
            $table->json('credit_withhold_statement')->nullable();
            $table->string('collection_policy_type')->default('customize');
            $table->string('collection_policy_interval')->default('monthly');
            $table->decimal('beginning_inventory_value', 14, 2)->default(0.00);
            $table->string('name');
            $table->integer('rm_inventory_coverage_days');
            $table->string('measurement_unit')->nullable();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();
            $table->decimal('vat_rate', 14, 4)->default(0.0000);
            $table->decimal('withhold_tax_rate', 14, 4)->default(0.0000);
            $table->json('inventory_value_statement')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_materials');
    }
};
