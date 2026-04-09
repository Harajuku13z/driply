<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outfit_tag', function (Blueprint $table) {
            $table->foreignUuid('outfit_id')->constrained('outfits')->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->primary(['outfit_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outfit_tag');
    }
};
