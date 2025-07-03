<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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
            // Change column to TEXT to support JSON array, using TEXT for broad DB compatibility
            $table->text('error_records')->nullable()->default(null)->change();
        });

        // Initialize existing records to an empty JSON array '[]' where the column is 0 or NULL
        DB::table('import_runs')->where('error_records', '0')->orWhereNull('error_records')->update(['error_records' => '[]']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->unsignedInteger('error_records')->default(0)->change();
        });
    }
};
