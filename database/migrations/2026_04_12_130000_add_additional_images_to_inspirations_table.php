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
            // Tableau d'URLs d'images supplémentaires (ex. carrousel Instagram)
            $table->json('additional_images')->nullable()->after('thumbnail_url');
            // URL favicon/icône de secours quand aucune image OG n'est disponible
            $table->string('favicon_url', 2048)->nullable()->after('additional_images');
        });
    }

    public function down(): void
    {
        Schema::table('inspirations', function (Blueprint $table) {
            $table->dropColumn(['additional_images', 'favicon_url']);
        });
    }
};
