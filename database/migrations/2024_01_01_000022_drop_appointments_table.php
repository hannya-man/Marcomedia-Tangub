<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Appointment booking feature was removed at the client's request.
        Schema::dropIfExists('appointments');
    }
    public function down(): void {
        // Intentionally left empty — the appointments feature and its
        // columns are gone; recreating an empty table isn't useful.
    }
};
