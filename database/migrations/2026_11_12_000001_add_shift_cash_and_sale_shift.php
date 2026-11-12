<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A shift starts with the cash already in the drawer (the float), and each sale remembers the shift
// it was rung up in, so a shift shows what was sold in it.
return new class extends Migration {
    public function up(): void {
        Schema::table('shifts', function (Blueprint $table) {
            $table->decimal('opening_cash', 10, 2)->default(0)->after('opened_at');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('user_id')->constrained('shifts');
        });
    }

    public function down(): void {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
        });
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('opening_cash');
        });
    }
};
