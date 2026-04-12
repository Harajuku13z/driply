<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspirations', function (Blueprint $table) {
            $table->text('title')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('inspirations', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
        });
    }
};
