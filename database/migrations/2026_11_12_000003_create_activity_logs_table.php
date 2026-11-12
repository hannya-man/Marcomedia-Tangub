<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Everyone's activity, for the owner's Activity Log and each shift's timeline: logging in and out,
// wrong passwords, and every action a page said was done, in its own words. Never edited.
return new class extends Migration {
    public function up(): void {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users');   // null: a login with an email no account has
            $table->foreignId('shift_id')->nullable()->constrained('shifts');
            $table->string('action', 80);                 // the page action (route name), or login, logout, login.failed
            $table->text('description');
            $table->boolean('needs_review')->default(false);   // shown under "Needs a look"
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['needs_review', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void {
        Schema::dropIfExists('activity_logs');
    }
};
