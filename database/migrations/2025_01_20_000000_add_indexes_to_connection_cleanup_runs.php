<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('connection_cleanup_runs', function (Blueprint $table) {
            // Add composite index for connection_id and status queries
            $table->index(['connection_id', 'status'], 'idx_connection_status');
            
            // Add index for status and created_at for dashboard filtering
            $table->index(['status', 'created_at'], 'idx_status_created');
            
            // Add index for type filtering
            $table->index('type', 'idx_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connection_cleanup_runs', function (Blueprint $table) {
            $table->dropIndex('idx_connection_status');
            $table->dropIndex('idx_status_created');
            $table->dropIndex('idx_type');
        });
    }
};
