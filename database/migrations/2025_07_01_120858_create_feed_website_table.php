<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_website', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feed_id')->constrained()->onDelete('cascade');
            $table->foreignId('website_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->json('filtering_rules')->nullable();
            $table->json('category_mappings')->nullable();
            $table->json('attribute_mappings')->nullable();
            $table->json('field_mappings')->nullable();
            $table->json('update_settings')->nullable();
            $table->string('schedule')->default('daily');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['feed_id', 'website_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_website');
    }
};
