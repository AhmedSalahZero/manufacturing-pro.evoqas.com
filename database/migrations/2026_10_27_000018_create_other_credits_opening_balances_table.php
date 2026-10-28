<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening balance: other creditors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_credits_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->decimal('amount', 14, 2)->default(0.00);
            $table->json('payload')->nullable();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();
            $table->json('statement')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_credits_opening_balances');
    }
};
