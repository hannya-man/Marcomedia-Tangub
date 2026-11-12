<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MariaDB (XAMPP, on the PC) quietly gives a table's first required TIMESTAMP column "ON UPDATE
// CURRENT_TIMESTAMP", so ending a shift rewrote when it started. These keep the time they were given.
// (MySQL 8 on Aiven never added it; there this changes nothing.)
return new class extends Migration {
    public function up(): void {
        Schema::table('shifts', function (Blueprint $table) {
            $table->timestamp('opened_at')->useCurrent()->change();
        });
        Schema::table('physical_counts', function (Blueprint $table) {
            $table->timestamp('counted_at')->useCurrent()->change();
        });
    }

    public function down(): void {
        // Nothing to undo: the old behavior was a fault.
    }
};
