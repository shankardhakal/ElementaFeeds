<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            // Add the two new, separate credential columns
            $table->text('woocommerce_credentials')->nullable()->after('platform');
            $table->text('wordpress_credentials')->nullable()->after('woocommerce_credentials');

            // Drop the old, single credentials column
            $table->dropColumn('credentials');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['woocommerce_credentials', 'wordpress_credentials']);
            $table->json('credentials')->nullable();
        });
    }
};