<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed assets come in four kinds: land, construction, machine (with delivery and installation) and other.
 *
 *   asset_type          land | construction | machine | other   (an asset saved before this existed is a construction)
 *   execution_curve     flat | s_curve   how a construction is spent over its months
 *   delivery_date       the month a machine is delivered, as a position in the study (end_date is then the month it is ready)
 *   milestone_payments  a machine paid by milestones: {"purchase": {"rate": 50, "days": 0}, "delivery": {...}, "ready": {...}}
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->string('asset_type')->default('construction');
            $table->string('execution_curve')->default('flat');
            $table->integer('delivery_date')->nullable();
            $table->json('milestone_payments')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn(['asset_type', 'execution_curve', 'delivery_date', 'milestone_payments']);
        });
    }
};
