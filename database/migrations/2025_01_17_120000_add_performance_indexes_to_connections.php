<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_website', function (Blueprint $table) {
            // Add indexes for search and filtering performance
            $table->index('name'); // For connection name searches
            $table->index('is_active'); // For status filtering
            $table->index(['is_active', 'created_at']); // Composite index for active connections ordering
            $table->index('last_run_at'); // For last run date sorting
        });

        // Add indexes to related tables for join performance
        Schema::table('feeds', function (Blueprint $table) {
            $table->index('name'); // For feed name searches
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->index('name'); // For website name searches
        });

        Schema::table('import_runs', function (Blueprint $table) {
            $table->index(['feed_website_id', 'created_at']); // For latest import run queries
            $table->index('status'); // For import status filtering
        });
    }

    public function down(): void
    {
        Schema::table('feed_website', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->dropIndex(['is_active']);
            $table->dropIndex(['is_active', 'created_at']);
            $table->dropIndex(['last_run_at']);
        });

        Schema::table('feeds', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });

        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropIndex(['feed_website_id', 'created_at']);
            $table->dropIndex(['status']);
        });
    }
};
