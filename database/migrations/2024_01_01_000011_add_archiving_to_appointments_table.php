<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('appointments', function (Blueprint $table) {
            // Guarded with hasColumn() checks so this can run safely even
            // though another migration (add_archive_fields_to_appointments_table)
            // already added archived_at. is_archived isn't used anywhere in
            // the app's actual logic (archiving is driven by the `status`
            // column being set to 'archived' instead) — it's kept here
            // harmlessly so nothing has to be deleted.
            if (!Schema::hasColumn('appointments', 'is_archived')) {
                $table->boolean('is_archived')->default(false)->after('status');
            }
            if (!Schema::hasColumn('appointments', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('is_archived');
            }
        });
    }
    public function down(): void {
        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasColumn('appointments', 'is_archived')) {
                $table->dropColumn('is_archived');
            }
            // archived_at is left alone on rollback here since the OTHER
            // migration (add_archive_fields_to_appointments_table) owns it
            // and will drop it on its own rollback.
        });
    }
};
