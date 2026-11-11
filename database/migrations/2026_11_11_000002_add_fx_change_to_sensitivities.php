<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product sales rebuild, Stage B: the "FX rate +/- %" what-if (R6.10). One value for the whole study: it moves every
 * foreign currency rate of the FX forecast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sensitivities', function (Blueprint $table) {
            $table->decimal('fx_rate_change', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sensitivities', function (Blueprint $table) {
            $table->dropColumn('fx_rate_change');
        });
    }
};
