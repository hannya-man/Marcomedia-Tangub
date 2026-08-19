<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // Reassign any existing photographer accounts to cashier before
        // the enum stops allowing that value at all — nobody gets locked
        // out of their account by this change.
        DB::table('users')->where('role', 'photographer')->update(['role' => 'cashier']);
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','cashier') NOT NULL DEFAULT 'cashier'");

        // The photographer-assignment feature (and its notification bell)
        // goes with the role — these columns were only ever used for that.
        // Guarded with hasTable() since the appointments table itself is
        // dropped in a later migration (000022) — this keeps fresh installs
        // working even though it no longer applies to any existing data.
        if (Schema::hasTable('appointments')) {
            Schema::table('appointments', function (Blueprint $table) {
                if (Schema::hasColumn('appointments', 'assigned_to')) {
                    $table->dropConstrainedForeignId('assigned_to');
                }
                if (Schema::hasColumn('appointments', 'seen_at')) {
                    $table->dropColumn('seen_at');
                }
            });
        }
    }
    public function down(): void {
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','cashier','photographer') NOT NULL DEFAULT 'cashier'");
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('seen_at')->nullable();
        });
    }
};
