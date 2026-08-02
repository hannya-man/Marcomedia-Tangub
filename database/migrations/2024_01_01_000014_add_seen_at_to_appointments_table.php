<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('appointments', function (Blueprint $table) {
            // Tracks whether the assigned photographer has seen this
            // assignment yet — drives the notification bell badge.
            $table->timestamp('seen_at')->nullable()->after('assigned_to');
        });
    }
    public function down(): void {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('seen_at');
        });
    }
};
