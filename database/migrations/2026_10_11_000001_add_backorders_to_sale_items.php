<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Marcomedia accepts orders even when an item is out of stock. Billing/POS sells it anyway and
// the line goes on order (backordered_at), taking nothing from stock yet. When the stock comes in
// the line takes it, oldest sale first, and backorder_filled_at is set (BackorderService).
return new class extends Migration {
    public function up(): void {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->timestamp('backordered_at')->nullable()->after('cut_blocks');
            $table->timestamp('backorder_filled_at')->nullable()->after('backordered_at');
            $table->index(['backordered_at', 'backorder_filled_at']);
        });
    }

    public function down(): void {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex(['backordered_at', 'backorder_filled_at']);
            $table->dropColumn(['backordered_at', 'backorder_filled_at']);
        });
    }
};
