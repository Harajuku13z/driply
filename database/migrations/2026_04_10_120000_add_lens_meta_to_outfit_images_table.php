<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outfit_images', function (Blueprint $table): void {
            $table->string('title')->nullable()->after('source');
            $table->string('buy_link', 2048)->nullable()->after('title');
            $table->decimal('price_found', 12, 2)->nullable()->after('buy_link');
        });
    }

    public function down(): void
    {
        Schema::table('outfit_images', function (Blueprint $table): void {
            $table->dropColumn(['title', 'buy_link', 'price_found']);
        });
    }
};
