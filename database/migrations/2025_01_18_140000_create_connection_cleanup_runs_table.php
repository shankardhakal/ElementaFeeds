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
        Schema::create('connection_cleanup_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')
                  ->constrained('feed_website')
                  ->onDelete('cascade');
            $table->enum('type', ['manual_deletion', 'stale_cleanup'])
                  ->comment('Distinguish between manual feed deletion and automated stale cleanup');
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled'])
                  ->default('pending');
            $table->unsignedInteger('products_found')->default(0);
            $table->unsignedInteger('products_processed')->default(0);
            $table->unsignedInteger('products_failed')->default(0);
            $table->boolean('dry_run')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_summary')->nullable()
                  ->comment('Only summary errors, not individual product failures');
            $table->timestamps();
            
            // Performance indexes
            $table->index(['connection_id', 'type']);
            $table->index(['status', 'started_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connection_cleanup_runs');
    }
};
