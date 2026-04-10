<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groupe_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('groupe_id')->constrained('groupes')->cascadeOnDelete();
            $table->foreignUuid('inspiration_id')->constrained('inspirations')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->text('note')->nullable();
            $table->timestamp('added_at')->useCurrent();
            $table->timestamps();

            $table->unique(['groupe_id', 'inspiration_id']);
            $table->index(['groupe_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groupe_items');
    }
};
