<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lens_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outfit_id')->nullable()->constrained('outfits')->nullOnDelete();
            $table->string('input_image_url');
            $table->json('lens_products')->nullable();
            $table->json('price_analysis')->nullable();
            $table->string('currency', 8)->default('EUR');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lens_results');
    }
};
