<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUniqueConstraintsToSyndicatedProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('syndicated_products', function (Blueprint $table) {
            $table->unique(['source_product_identifier', 'feed_website_id'], 'unique_source_product_feed');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('syndicated_products', function (Blueprint $table) {
            $table->dropUnique('unique_source_product_feed');
        });
    }
}
