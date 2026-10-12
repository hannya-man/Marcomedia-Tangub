<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets the Products page and Billing/POS list categories and products in the shop's own
// order (the flyer's), instead of alphabetically. Empty means "after the ordered ones".
return new class extends Migration {
    public function up(): void {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->nullable()->after('slug');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->nullable()->after('category_id');
        });
    }

    public function down(): void {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
