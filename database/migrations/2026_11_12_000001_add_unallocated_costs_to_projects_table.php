<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Costs no product could take in a month (no product was making or selling): they are shown in general and administrative expenses
// of that month. Filled by the calculation engine; nothing to type.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('unallocated_costs')->nullable();                // type (direct_labor, manufacturing_overheads, expenses) => month => cost
            $table->json('unallocated_depreciation')->nullable();         // machines bought in the study: month => depreciation
            $table->json('unallocated_opening_depreciation')->nullable(); // machines owned on the first day: month => depreciation
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['unallocated_costs', 'unallocated_depreciation', 'unallocated_opening_depreciation']);
        });
    }
};
