<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Replaces the logbook check. Opened and resolved automatically by
        // StockAlertService every time a batch balance changes.
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            $table->enum('type', ['low_stock', 'out_of_stock', 'transfer_needed']);
            $table->enum('status', ['active', 'resolved'])->default('active');
            // Same trick as inventory_batches.open_guard: only ONE active alert per material and type,
            // so a busy day of sales updates the same alert instead of flooding the owner.
            $table->unsignedTinyInteger('active_guard')->nullable()->storedAs("IF(`status` = 'active', 1, NULL)");
            $table->decimal('stock_level', 12, 3);                 // total stock when last checked
            $table->decimal('threshold', 12, 3)->nullable();       // reorder point at that time
            $table->string('message', 255);
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['material_id', 'type', 'active_guard'], 'one_active_alert_per_type');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('stock_alerts');
    }
};
