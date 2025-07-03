<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReconciliationMetricsToImportRunsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('import_runs', function (Blueprint $table) {
            // Only add the reconciled_at column if it doesn't exist
            if (!Schema::hasColumn('import_runs', 'reconciled_at')) {
                // First check if finished_at exists, otherwise we can't add after it
                if (Schema::hasColumn('import_runs', 'finished_at')) {
                    $table->timestamp('reconciled_at')->nullable()->after('finished_at');
                } else {
                    $table->timestamp('reconciled_at')->nullable();
                }
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
            if (Schema::hasColumn('import_runs', 'reconciled_at')) {
                $table->dropColumn('reconciled_at');
            }
        });
    }
}
