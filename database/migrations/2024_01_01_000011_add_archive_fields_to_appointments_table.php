<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // Widen the status enum to include 'archived' — done via raw SQL
        // so this doesn't require the doctrine/dbal package that
        // Schema::table()->enum()->change() normally needs.
        DB::statement("ALTER TABLE appointments MODIFY status ENUM('pending','confirmed','done','cancelled','archived') NOT NULL DEFAULT 'pending'");

        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
            $table->enum('archived_reason', ['manual', 'expired'])->nullable()->after('archived_at');
        });
    }
    public function down(): void {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'archived_reason']);
        });
        DB::statement("ALTER TABLE appointments MODIFY status ENUM('pending','confirmed','done','cancelled') NOT NULL DEFAULT 'pending'");
    }
};
