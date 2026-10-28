<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff positions: salaries, counts and the calculated monthly cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manpowers', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('position');
            $table->string('avg_salary');
            $table->string('existing_count');
            $table->json('hirings');
            $table->json('salary_expenses')->nullable();
            $table->json('tax_and_social_insurance_statement')->nullable();
            $table->json('salary_payments')->nullable();
            $table->json('accumulated_manpower_counts')->nullable();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manpowers');
    }
};
