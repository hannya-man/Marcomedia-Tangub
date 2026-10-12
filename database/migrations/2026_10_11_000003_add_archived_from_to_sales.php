<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What a sale was before it was archived (status becomes 'archived'), so restoring it puts it back
// as it was. Restore used to make every sale 'completed', so a voided or unpaid one came back
// counting as revenue. Sales archived before this came back 'completed' as before.
return new class extends Migration {
    public function up(): void {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('archived_from', 20)->nullable()->after('status');
        });
    }

    public function down(): void {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('archived_from');
        });
    }
};
