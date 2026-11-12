<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Every payment on a sale, each on its own row: the one at the counter and each one after it (a down
// payment paid off later), with how it was paid, who took it and on which shift. So a sale shows its
// own payment history. sales.amount_paid stays the running total the rest of the app reads.
return new class extends Migration {
    public function up(): void {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales');
            $table->decimal('amount', 10, 2);                 // what went to the sale; change given back isn't in it
            $table->enum('method', ['cash', 'gcash']);
            $table->string('reference', 100)->nullable();    // the GCash reference number
            $table->string('note', 255)->nullable();
            $table->foreignId('received_by')->constrained('users');
            $table->foreignId('shift_id')->nullable()->constrained('shifts');
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
        });

        // Sales from before kept only their total paid: it becomes one payment, at the sale's time.
        DB::statement("INSERT INTO sale_payments (sale_id, amount, method, note, received_by, created_at)
            SELECT id, amount_paid - change_amount, payment_method, 'Paid before each payment was kept on its own', user_id, created_at
            FROM sales WHERE amount_paid - change_amount > 0");
    }

    public function down(): void {
        Schema::dropIfExists('sale_payments');
    }
};
