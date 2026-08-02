<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // 'processing' = paid in full but the job/item itself isn't done/released yet
        // (e.g. fully paid but printing is still ongoing) — staff mark it
        // 'completed' manually once the item is actually released.
        // 'pending' = only partially paid (a down payment), balance still owed.
        // 'archived' = old record swept out of the main list (2+ years old).
        DB::statement("ALTER TABLE sales MODIFY status ENUM('pending','processing','completed','voided','archived') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE orders MODIFY status ENUM('pending','processing','completed','cancelled','archived') NOT NULL DEFAULT 'pending'");

        Schema::table('sales', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
        });
    }
    public function down(): void {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
        DB::statement("ALTER TABLE sales MODIFY status ENUM('completed','voided','pending') NOT NULL DEFAULT 'completed'");
        DB::statement("ALTER TABLE orders MODIFY status ENUM('pending','processing','completed','cancelled') NOT NULL DEFAULT 'pending'");
    }
};
