<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('material_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->timestamps();
        });

        // Nullable so your existing fabric/ink rows keep working without
        // being recategorized immediately.
        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('material_category_id')->nullable()->after('id')->constrained('material_categories')->nullOnDelete();
        });
    }
    public function down(): void {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_category_id');
        });
        Schema::dropIfExists('material_categories');
    }
};