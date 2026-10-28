<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly sales pattern (flat, quarterly or monthly). The monthly split is calculated in PHP, not in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasonality', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('model_id');
            $table->string('model_name');
            $table->enum('type', ['flat', 'quarterly', 'monthly'])->default('flat');
            $table->json('percentages');
            $table->json('distributed_percentages');
            $table->timestamps();
            $table->index(['model_name', 'model_id']);
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasonality');
    }
};
