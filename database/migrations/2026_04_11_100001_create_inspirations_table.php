<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspirations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('type', 32);

            $table->string('scan_query')->nullable();
            $table->string('scan_item_type')->nullable();
            $table->string('scan_brand')->nullable();
            $table->string('scan_color')->nullable();
            $table->json('scan_results')->nullable();
            $table->json('scan_price_summary')->nullable();

            $table->string('source_url', 2048)->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('media_url', 2048)->nullable();
            $table->string('thumbnail_url', 2048)->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('media_type', 16)->nullable();

            $table->text('note')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->json('tags')->nullable();
            $table->string('status', 16)->default('processed');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'is_favorite']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspirations');
    }
};
