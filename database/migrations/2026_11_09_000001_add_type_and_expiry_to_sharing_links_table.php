<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A link is either "view" (the whole study, looking only) or "fill" (the customer fills in the input pages), and may have an
 * expiry date (the last day it works; empty = never).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sharing_links', function (Blueprint $table) {
            $table->string('type', 10)->default('view')->after('link_code');
            $table->date('expires_at')->nullable()->after('closed');
        });
    }

    public function down(): void
    {
        Schema::table('sharing_links', function (Blueprint $table) {
            $table->dropColumn(['type', 'expires_at']);
        });
    }
};
