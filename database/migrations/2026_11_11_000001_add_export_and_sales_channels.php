<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product sales rebuild, Stage A: export on/off for the study, foreign currencies with a yearly FX forecast,
 * sales channels (local customers), export markets (countries), the allocation of each product over them,
 * and a second seasonality pattern per product (local / export).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // R1.1: "This study has export sales". New studies start with No.
            $table->boolean('has_export_sales')->default(false);
        });

        // R1.2 / R1.3: one row per foreign currency of the study. rates = { year index: units of the study currency per 1 foreign unit }.
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('currency', 10);
            $table->json('rates')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'currency']);
        });

        // R1.4: customer segments (Retail, Wholesale, Distributors ...), named by the user.
        Schema::create('sales_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'name']);
        });

        // R1.5: export markets (country + currency).
        Schema::create('export_markets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('currency', 10);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'name']);
        });

        // R2, R3, R5: how a product uses a channel (local) or a market (export). Filled by the product form (Stage C).
        // D8: a channel / market that a product uses cannot be deleted. The study form refuses it with a message that lists the
        // products (UpdateProjectRequest); the database cascades only so that deleting a whole study or product never gets stuck.
        Schema::create('product_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('kind', 10);   // 'local' | 'export'
            $table->foreignId('sales_channel_id')->nullable()->constrained('sales_channels')->cascadeOnDelete();
            $table->foreignId('export_market_id')->nullable()->constrained('export_markets')->cascadeOnDelete();
            $table->json('allocation_percentages')->nullable();   // year index => % of the group quantity
            $table->json('price_differences')->nullable();        // local: year index => % from the base local price
            $table->decimal('first_foreign_price', 20, 2)->nullable();   // export: price in the market's currency, first year
            $table->json('foreign_growth_rates')->nullable();     // export: year index => yearly growth %
            $table->boolean('uses_own_terms')->default(false);    // R5.2: false = the product's default terms
            $table->json('collection_policy_value')->nullable(); // own terms (same shape as the product's)
            $table->json('monthly_quantities')->nullable();       // saved results, filled by the calculation (Stage B)
            $table->json('monthly_values')->nullable();
            $table->json('collection_statement')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'kind']);
        });

        // R4: two patterns per product. Existing rows are the local pattern.
        Schema::table('seasonality', function (Blueprint $table) {
            $table->string('scope', 10)->default('local');
        });
    }

    public function down(): void
    {
        Schema::table('seasonality', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
        Schema::dropIfExists('product_segments');
        Schema::dropIfExists('export_markets');
        Schema::dropIfExists('sales_channels');
        Schema::dropIfExists('fx_rates');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('has_export_sales');
        });
    }
};
