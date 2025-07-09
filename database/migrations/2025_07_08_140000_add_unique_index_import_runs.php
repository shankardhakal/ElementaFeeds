<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            // Ensure only one 'processing' run per connection
            $table->unique(['feed_website_id', 'status'], 'import_runs_conn_status_unique');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropUnique('import_runs_conn_status_unique');
        });
    }
};
