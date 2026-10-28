<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The study / business plan: name, dates, duration, tax and return rates, manpower settings. Everything else hangs on a project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_completed')->default(0);
            $table->json('operation_dates')->nullable();
            $table->json('extended_operation_dates')->nullable();
            $table->json('study_dates')->nullable();
            $table->json('extended_study_dates')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->boolean('new_company')->nullable()->default(0);
            $table->date('start_date')->nullable();
            $table->string('operation_start_date')->nullable();
            $table->integer('duration')->nullable();
            $table->date('end_date')->nullable();
            $table->date('extended_end_date')->nullable();
            $table->date('selling_start_date')->nullable();
            $table->foreignId('business_sector_id')->nullable()->constrained('business_sectors')->nullOnDelete();
            $table->decimal('perpetual_growth_rate', 10, 2)->nullable();
            $table->decimal('return_rate', 10, 2)->nullable();
            $table->decimal('tax_rate', 10, 2)->nullable();
            $table->string('product_first')->nullable();
            $table->date('product_first_selling_date')->nullable();
            $table->string('product_second')->nullable();
            $table->date('product_second_selling_date')->nullable();
            $table->string('product_third')->nullable();
            $table->date('product_third_selling_date')->nullable();
            $table->string('product_fourth')->nullable();
            $table->date('product_fourth_selling_date')->nullable();
            $table->string('product_fifth')->nullable();
            $table->date('product_fifth_selling_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->json('manpower_allocations')->nullable();
            $table->json('monthly_manpower_allocations')->nullable();
            $table->json('manpower_is_as_revenue_percentages')->nullable();
            $table->decimal('salaries_annual_increase', 14, 2)->default(0.00);
            $table->decimal('salaries_tax_rate', 14, 2)->default(0.00);
            $table->decimal('social_insurance_rate', 14, 2)->default(0.00);
            $table->longText('comment_for_raw_materials')->nullable();
            $table->json('vat_statements')->nullable();
            $table->json('corporate_taxes_statement')->nullable();
            $table->longText('intro_paragraph')->nullable();
            $table->longText('dashboard_comment_1')->nullable();
            $table->longText('dashboard_comment_2')->nullable();
            $table->string('main_functional_currency')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
