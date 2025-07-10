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
        // Drop the syndicated_products table as we're moving to stateless architecture
        Schema::dropIfExists('syndicated_products');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the syndicated_products table if rollback is needed
        Schema::create('syndicated_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('feed_website_id');
            $table->string('source_product_identifier');
            $table->string('destination_product_id');
            $table->string('last_updated_hash')->nullable();
            $table->timestamps();

            $table->foreign('feed_website_id')->references('id')->on('feed_website')->onDelete('cascade');
            $table->unique(['feed_website_id', 'source_product_identifier']);
        });
    }
};
