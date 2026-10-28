<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who opened a shared link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sharing_links_visitors', function (Blueprint $table) {
            $table->id();
            $table->string('ip')->nullable();
            $table->string('link_code')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sharing_links_visitors');
    }
};
