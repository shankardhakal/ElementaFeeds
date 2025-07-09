<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syndicated_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feed_website_id')->constrained('feed_website')->onDelete('cascade');
            $table->string('source_product_identifier')->index();
            $table->string('destination_product_id')->index();
            $table->string('last_updated_hash');
            $table->timestamps();

            $table->unique(['source_product_identifier', 'feed_website_id'], 'unique_source_feed_constraint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syndicated_products');
    }
};