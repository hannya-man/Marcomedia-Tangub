<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('variant_name', 100);   // "Small (S)", "10 inches"
            $table->string('sku', 60)->unique();
            $table->json('attributes')->nullable(); // {"size":"M"}
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->integer('low_stock_threshold')->default(5);
            $table->integer('reorder_point')->default(10);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->index(['stock_quantity', 'low_stock_threshold']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('product_variants');
    }
};
