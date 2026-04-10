<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\InspirationStatusEnum;
use App\Enums\InspirationTypeEnum;
use App\Enums\MediaTypeEnum;
use App\Models\Groupe;
use App\Models\Inspiration;
use App\Models\User;
use App\Support\GroupeCoverManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'test@driply.app'],
            [
                'name' => 'Test Driply',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'currency' => 'EUR',
            ]
        );

        if ($user->groupes()->count() > 0) {
            return;
        }

        $g1 = Groupe::query()->create([
            'user_id' => $user->id,
            'name' => 'Look automne 2025',
            'description' => 'Tons chauds et layering',
            'position' => 0,
        ]);

        $g2 = Groupe::query()->create([
            'user_id' => $user->id,
            'name' => 'Street',
            'description' => 'Inspirations urbaines',
            'position' => 1,
        ]);

        $g3 = Groupe::query()->create([
            'user_id' => $user->id,
            'name' => 'Soirées',
            'description' => null,
            'position' => 2,
        ]);

        $thumb = fn (string $s): string => 'https://picsum.photos/seed/'.$s.'/400/500';

        $inspirations = [
            $this->createInspiration($user->id, InspirationTypeEnum::Scan, 'Jean slim noir', $thumb('scan1')),
            $this->createInspiration($user->id, InspirationTypeEnum::Instagram, 'Post mode', $thumb('ig1')),
            $this->createInspiration($user->id, InspirationTypeEnum::Photo, 'Photo perso', $thumb('ph1')),
            $this->createInspiration($user->id, InspirationTypeEnum::Tiktok, 'Vidéo try-on', $thumb('tt1')),
            $this->createInspiration($user->id, InspirationTypeEnum::Scan, 'Blazer beige', $thumb('scan2')),
            $this->createInspiration($user->id, InspirationTypeEnum::Youtube, 'Haul YouTube', $thumb('yt1')),
            $this->createInspiration($user->id, InspirationTypeEnum::Other, 'Lien divers', $thumb('ot1')),
            $this->createInspiration($user->id, InspirationTypeEnum::Instagram, 'Story capture', $thumb('ig2')),
        ];

        $this->attachToGroupe($g1, [$inspirations[0], $inspirations[1], $inspirations[2]]);
        $this->attachToGroupe($g2, [$inspirations[3], $inspirations[4]]);
        $this->attachToGroupe($g3, [$inspirations[5], $inspirations[6], $inspirations[7], $inspirations[0]]);
    }

    private function createInspiration(string $userId, InspirationTypeEnum $type, string $title, string $thumbUrl): Inspiration
    {
        $data = [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'thumbnail_url' => $thumbUrl,
            'status' => InspirationStatusEnum::Processed,
            'is_favorite' => false,
            'tags' => ['casual', 'inspo'],
        ];

        if ($type === InspirationTypeEnum::Scan) {
            $data['scan_item_type'] = 'vêtement';
            $data['scan_brand'] = 'Driply';
            $data['scan_color'] = 'neutre';
            $data['scan_results'] = [[
                'rank_label' => 'Meilleur prix',
                'title' => 'Article seed',
                'price' => 39.99,
                'price_formatted' => '39,99 €',
                'source' => 'demo',
                'link' => 'https://example.com',
                'thumbnail' => $thumbUrl,
                'in_stock' => true,
            ]];
            $data['scan_price_summary'] = ['lowest' => 39.99, 'average' => 45.0, 'highest' => 59.99];
        }

        if (in_array($type, [InspirationTypeEnum::Tiktok, InspirationTypeEnum::Instagram, InspirationTypeEnum::Youtube, InspirationTypeEnum::Other], true)) {
            $data['source_url'] = 'https://example.com/p/'.$type->value;
            $data['platform'] = $type->value;
            $data['media_type'] = MediaTypeEnum::Image;
        }

        return Inspiration::query()->create($data);
    }

    /**
     * @param  array<int, Inspiration>  $list
     */
    private function attachToGroupe(Groupe $groupe, array $list): void
    {
        foreach ($list as $inspiration) {
            GroupeCoverManager::attachInspiration($groupe->fresh(), $inspiration, []);
        }

        GroupeCoverManager::refreshCoverIfNeeded($groupe->fresh());
    }
}
