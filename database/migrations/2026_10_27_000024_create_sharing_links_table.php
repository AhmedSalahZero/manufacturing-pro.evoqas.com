<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links used to share a project with other people.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sharing_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('link_code')->nullable()->unique();
            $table->boolean('closed')->default(0);
            $table->tinyInteger('open')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sharing_links');
    }
};
