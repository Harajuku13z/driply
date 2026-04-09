<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imported_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outfit_id')->nullable()->constrained('outfits')->nullOnDelete();
            $table->string('platform', 16);
            $table->text('source_url');
            $table->string('local_path')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('type', 16);
            $table->string('status', 16)->default('pending');
            $table->json('frames')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'platform', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_media');
    }
};
