<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The monthly depreciation of an owned asset could only hold up to 999,999.99 (decimal 8,2 in the old database).
// Large plants can have more, so it now holds the same size as every other amount (14 digits).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_asset_opening_balances', function (Blueprint $table) {
            $table->decimal('monthly_depreciation', 14, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_asset_opening_balances', function (Blueprint $table) {
            $table->decimal('monthly_depreciation', 8, 2)->default(0)->change();
        });
    }
};
