<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // A single shared staff channel — everyone logged in (admin,
        // cashier, photographer) sees and posts to the same conversation.
        // Kept intentionally simple (no DMs/threads) to match the actual
        // ask: a live way for staff on shift to talk to each other.
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->json('reactions')->nullable(); // {"👍": [1,2], "❤️": [3]} = user IDs per emoji
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('chat_messages');
    }
};
