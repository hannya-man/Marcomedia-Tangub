<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        DB::statement("ALTER TABLE products MODIFY status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active'");
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
        });
    }
    public function down(): void {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
        DB::statement("ALTER TABLE products MODIFY status ENUM('active','inactive') NOT NULL DEFAULT 'active'");
    }
};
