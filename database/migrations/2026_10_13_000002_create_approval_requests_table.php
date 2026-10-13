<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What a cashier asks an owner to approve: adding a product, changing one, or voiding a sale.
// Nothing changes until an owner approves it on the Approvals page.
return new class extends Migration {
    public function up(): void {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);                   // product.create | product.update | sale.void
            $table->string('status', 20)->default('pending');  // pending | approved | rejected | cancelled
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('summary', 255);
            $table->json('payload')->nullable();          // the product form as the cashier sent it
            $table->string('reason', 500)->nullable();    // the cashier's why (a void)
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('requester_seen_at')->nullable();   // the cashier has seen the answer
            $table->timestamps();

            $table->index(['status', 'type']);
            $table->index(['requested_by', 'status']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('approval_requests');
    }
};
