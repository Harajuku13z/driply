<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('scan_session_id')->constrained('scan_sessions')->cascadeOnDelete();
            $table->json('image_ids');
            $table->float('similarity_score')->default(0);
            $table->boolean('resolved')->default(false);
            $table->string('resolution_action')->nullable();
            $table->timestamps();

            $table->index(['scan_session_id', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_groups');
    }
};
