<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->constrained('sale_items');
            $table->integer('quantity');
            $table->enum('condition', ['good', 'damaged']);
            $table->string('reason')->nullable();
            $table->foreignId('processed_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
        });
    }
    public function down(): void {
        Schema::dropIfExists('returns');
    }
};
