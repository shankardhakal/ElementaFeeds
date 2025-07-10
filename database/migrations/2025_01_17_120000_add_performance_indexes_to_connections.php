<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only add indexes if tables exist
        if (Schema::hasTable('feed_website')) {
            Schema::table('feed_website', function (Blueprint $table) {
                // Add indexes for search and filtering performance
                if (!Schema::hasColumn('feed_website', 'name')) return;
                
                $table->index('name'); // For connection name searches
                $table->index('is_active'); // For status filtering
                $table->index(['is_active', 'created_at']); // Composite index for active connections ordering
                $table->index('last_run_at'); // For last run date sorting
            });
        }

        // Add indexes to related tables for join performance
        if (Schema::hasTable('feeds')) {
            Schema::table('feeds', function (Blueprint $table) {
                if (Schema::hasColumn('feeds', 'name')) {
                    $table->index('name'); // For feed name searches
                }
            });
        }

        if (Schema::hasTable('websites')) {
            Schema::table('websites', function (Blueprint $table) {
                if (Schema::hasColumn('websites', 'name')) {
                    $table->index('name'); // For website name searches
                }
            });
        }

        if (Schema::hasTable('import_runs')) {
            Schema::table('import_runs', function (Blueprint $table) {
                if (Schema::hasColumn('import_runs', 'feed_website_id') && Schema::hasColumn('import_runs', 'created_at')) {
                    $table->index(['feed_website_id', 'created_at']); // For latest import run queries
                }
                if (Schema::hasColumn('import_runs', 'status')) {
                    $table->index('status'); // For import status filtering
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('feed_website')) {
            Schema::table('feed_website', function (Blueprint $table) {
                $table->dropIndex(['name']);
                $table->dropIndex(['is_active']);
                $table->dropIndex(['is_active', 'created_at']);
                $table->dropIndex(['last_run_at']);
            });
        }

        if (Schema::hasTable('feeds')) {
            Schema::table('feeds', function (Blueprint $table) {
                $table->dropIndex(['name']);
            });
        }

        if (Schema::hasTable('websites')) {
            Schema::table('websites', function (Blueprint $table) {
                $table->dropIndex(['name']);
            });
        }

        if (Schema::hasTable('import_runs')) {
            Schema::table('import_runs', function (Blueprint $table) {
                $table->dropIndex(['feed_website_id', 'created_at']);
                $table->dropIndex(['status']);
            });
        }
    }
};
