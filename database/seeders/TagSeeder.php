<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'Casual',
            'Streetwear',
            'Formel',
            'Vintage',
            'Sportswear',
            'Minimaliste',
        ];

        foreach ($defaults as $name) {
            $slug = Str::slug($name);
            Tag::query()->firstOrCreate(
                ['slug' => $slug, 'user_id' => null],
                ['name' => $name]
            );
        }
    }
}
