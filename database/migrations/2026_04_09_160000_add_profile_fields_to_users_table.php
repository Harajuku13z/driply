<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('password');
            $table->string('plan', 16)->default('free')->after('avatar');
            $table->string('currency_preference', 8)->default('EUR')->after('plan');
            $table->unsignedInteger('outfits_count')->default(0)->after('currency_preference');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar', 'plan', 'currency_preference', 'outfits_count']);
        });
    }
};
