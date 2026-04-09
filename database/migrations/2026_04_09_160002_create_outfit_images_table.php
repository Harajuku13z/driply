<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outfit_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('outfit_id')->constrained('outfits')->cascadeOnDelete();
            $table->string('url');
            $table->string('source', 32);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['outfit_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outfit_images');
    }
};
