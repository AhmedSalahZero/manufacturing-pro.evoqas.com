<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The operation start is saved as a position in the study months (0 = the study's first month), the same way
// every product's selling start is, so that moving the study start moves all of them. Dates already saved are converted.
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('projects')->whereNotNull('operation_start_date')->get(['id', 'start_date', 'operation_start_date']) as $row) {
            $value = trim((string) $row->operation_start_date);

            if (preg_match('/^(\d{4})-(\d{2})/', $value, $op) && preg_match('/^(\d{4})-(\d{2})/', (string) $row->start_date, $start)) {
                $index = max(0, ((int) $op[1] * 12 + (int) $op[2]) - ((int) $start[1] * 12 + (int) $start[2]));
            } else {
                $index = ctype_digit($value) ? (int) $value : 0;
            }

            DB::table('projects')->where('id', $row->id)->update(['operation_start_date' => (string) $index]);
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedSmallInteger('operation_start_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('operation_start_date')->nullable()->change();
        });
    }
};
