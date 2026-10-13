<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Two-step verification (an authenticator app's 6-digit code after the password; required for
// owners), and deleting a cashier's account. A deleted account is only marked (deleted_at): its
// name stays on the sales, shifts and stock history it made.
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            // Encrypted with the app key. Set when setup starts; in use once confirmed with a code.
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            // The 30-second step of the last code accepted: a code can't be used twice.
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
            $table->softDeletes();
        });
    }

    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step', 'deleted_at']);
        });
    }
};
