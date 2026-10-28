<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What-if settings of a project: price, quantity and cost changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensitivities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->decimal('target_product_first', 14, 2)->nullable();
            $table->decimal('target_product_second', 14, 2)->nullable();
            $table->decimal('target_product_third', 14, 2)->nullable();
            $table->decimal('target_product_fourth', 14, 2)->nullable();
            $table->decimal('target_product_fifth', 14, 2)->nullable();
            $table->decimal('rm_product_first', 14, 2)->nullable();
            $table->decimal('rm_product_second', 14, 2)->nullable();
            $table->decimal('rm_product_third', 14, 2)->nullable();
            $table->decimal('rm_product_fourth', 14, 2)->nullable();
            $table->decimal('rm_product_fifth', 14, 2)->nullable();
            $table->integer('collections_product_first')->nullable();
            $table->integer('collections_product_second')->nullable();
            $table->integer('collections_product_third')->nullable();
            $table->integer('collections_product_fourth')->nullable();
            $table->integer('collections_product_fifth')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensitivities');
    }
};
