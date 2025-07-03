<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('import_runs', function (Blueprint $table) {
            // Add columns for tracking failed and skipped records
            $table->unsignedInteger('failed_records')->default(0)->after('deleted_records');
            $table->unsignedInteger('skipped_records')->default(0)->after('failed_records');
            
            // Rename columns to match the code if they exist with different names
            if (Schema::hasColumn('import_runs', 'processed_count')) {
                Schema::table('import_runs', function (Blueprint $table) {
                    $table->renameColumn('processed_count', 'processed_records');
                });
            }
            
            if (Schema::hasColumn('import_runs', 'skipped_count')) {
                Schema::table('import_runs', function (Blueprint $table) {
                    $table->renameColumn('skipped_count', 'skipped_records');
                });
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropColumn(['failed_records', 'skipped_records']);
            
            // If we renamed columns in the up() method, rename them back
            if (Schema::hasColumn('import_runs', 'processed_records')) {
                Schema::table('import_runs', function (Blueprint $table) {
                    $table->renameColumn('processed_records', 'processed_count');
                });
            }
            
            if (Schema::hasColumn('import_runs', 'skipped_records')) {
                Schema::table('import_runs', function (Blueprint $table) {
                    $table->renameColumn('skipped_records', 'skipped_count');
                });
            }
        });
    }
};
