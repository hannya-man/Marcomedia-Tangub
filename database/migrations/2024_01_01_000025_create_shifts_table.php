<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_sales', 10, 2)->nullable();
            $table->decimal('actual_cash', 10, 2)->nullable();
            $table->decimal('variance', 10, 2)->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('shifts');
    }
};
