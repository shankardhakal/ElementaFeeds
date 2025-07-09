<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('feed_website', function (Blueprint $table) {
            $table->string('category_source_field')->nullable()->after('filtering_rules');
            $table->string('category_delimiter')->default('>')->after('category_mappings');
        });
    }

    public function down(): void
    {
        Schema::table('feed_website', function (Blueprint $table) {
            $table->dropColumn(['category_source_field', 'category_delimiter']);
        });
    }
};
