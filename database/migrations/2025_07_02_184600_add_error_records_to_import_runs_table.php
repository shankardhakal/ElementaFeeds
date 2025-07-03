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
            $table->unsignedInteger('error_records')->default(0)->after('updated_records');
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
            $table->dropColumn('error_records');
        });
    }
};
