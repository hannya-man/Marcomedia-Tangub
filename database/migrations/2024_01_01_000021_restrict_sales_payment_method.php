<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // Any existing sales recorded as card/bank_transfer get reassigned
        // to cash before the enum stops allowing those values, so no
        // existing record ends up with an invalid value.
        DB::table('sales')->whereIn('payment_method', ['card', 'bank_transfer'])->update(['payment_method' => 'cash']);
        DB::statement("ALTER TABLE sales MODIFY payment_method ENUM('cash','gcash') NOT NULL DEFAULT 'cash'");
    }
    public function down(): void {
        DB::statement("ALTER TABLE sales MODIFY payment_method ENUM('cash','gcash','card','bank_transfer') NOT NULL DEFAULT 'cash'");
    }
};
