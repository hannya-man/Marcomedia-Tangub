<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            $table->string('batch_number', 30)->unique();
            $table->enum('location', ['store', 'warehouse']); // no default — every batch must state where it landed
            $table->decimal('opening_quantity', 10, 3);
            $table->decimal('remaining_quantity', 10, 3);
            $table->decimal('cost_per_unit', 10, 2)->nullable();
            $table->enum('status', ['unopened', 'open', 'closed'])->default('unopened');
            $table->date('received_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['material_id', 'location', 'status']);
        });

        Schema::create('batch_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_batch_id')->constrained('inventory_batches')->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->decimal('quantity_consumed', 10, 3);
            $table->decimal('revenue', 10, 2)->default(0);
            $table->timestamp('created_at')->nullable();
        });
    }
    public function down(): void {
        Schema::dropIfExists('batch_consumptions');
        Schema::dropIfExists('inventory_batches');
    }
};
