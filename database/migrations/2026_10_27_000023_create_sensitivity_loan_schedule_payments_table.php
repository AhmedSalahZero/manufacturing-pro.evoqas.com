<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loan repayment schedule lines for the what-if (sensitivity) case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensitivity_loan_schedule_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->nullable()->constrained('fixed_assets')->cascadeOnDelete();
            $table->integer('month_as_index');
            $table->string('loan_type')->nullable();
            $table->longText('beginning');
            $table->longText('interestAmount');
            $table->longText('schedulePayment');
            $table->longText('InterestPayment')->nullable();
            $table->longText('principleAmount');
            $table->longText('endBalance');
            $table->timestamps();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->json('accured_interest')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensitivity_loan_schedule_payments');
    }
};
