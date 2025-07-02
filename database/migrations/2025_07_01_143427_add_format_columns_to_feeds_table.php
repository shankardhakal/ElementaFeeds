<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * This adds columns to the feeds table to store specific parsing formats,
     * allowing the system to handle varied CSV files (e.g., tab-separated).
     */
    public function up(): void
    {
        Schema::table('feeds', function (Blueprint $table) {
            $table->string('delimiter', 10)->default(',')->after('is_active');
            $table->string('enclosure', 10)->default('"')->after('delimiter');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('feeds', function (Blueprint $table) {
            $table->dropColumn(['delimiter', 'enclosure']);
        });
    }
};