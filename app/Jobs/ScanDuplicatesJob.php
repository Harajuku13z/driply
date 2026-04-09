<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ScanSessionStatus;
use App\Models\DuplicateGroup;
use App\Models\OutfitImage;
use App\Models\ScanSession;
use App\Services\PHashService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ScanDuplicatesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const THRESHOLD = 12;

    public int $tries = 2;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 180];

    public function __construct(
        public string $scanSessionId,
    ) {
        $this->timeout = 600;
    }

    public function handle(PHashService $pHash): void
    {
        $session = ScanSession::query()->find($this->scanSessionId);
        if ($session === null) {
            return;
        }

        $session->status = ScanSessionStatus::Running;
        $session->started_at = now();
        $session->save();

        try {
            $images = OutfitImage::query()
                ->whereHas('outfit', fn ($q) => $q->where('user_id', $session->user_id))
                ->get();

            $session->total_images = $images->count();
            $session->save();

            $entries = [];
            $processed = 0;

            foreach ($images as $image) {
                $absolute = Storage::disk('public')->path($image->url);
                try {
                    $hash = $pHash->computeHash($absolute);
                } catch (RuntimeException) {
                    $processed++;
                    $session->processed_images = $processed;
                    $session->save();

                    continue;
                }

                $entries[] = ['id' => $image->id, 'hash' => $hash];
                $processed++;
                $session->processed_images = $processed;
                $session->save();
            }

            $clusters = $this->cluster($entries, $pHash);
            $dupCount = 0;

            foreach ($clusters as $cluster) {
                if (count($cluster) < 2) {
                    continue;
                }

                $dupCount++;
                DuplicateGroup::query()->create([
                    'scan_session_id' => $session->id,
                    'image_ids' => $cluster,
                    'similarity_score' => 1.0 - (self::THRESHOLD / 64.0),
                    'resolved' => false,
                ]);
            }

            $session->duplicates_found = $dupCount;
            $session->status = ScanSessionStatus::Completed;
            $session->completed_at = now();
            $session->save();
        } catch (Throwable $e) {
            $session->status = ScanSessionStatus::Failed;
            $session->completed_at = now();
            $session->save();

            throw $e;
        }
    }

    /**
     * @param  list<array{id: string, hash: string}>  $entries
     * @return list<list<string>>
     */
    private function cluster(array $entries, PHashService $pHash): array
    {
        $n = count($entries);
        if ($n === 0) {
            return [];
        }

        /** @var list<list<int>> $adj */
        $adj = array_fill(0, $n, []);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($pHash->hammingDistanceHex($entries[$i]['hash'], $entries[$j]['hash']) <= self::THRESHOLD) {
                    $adj[$i][] = $j;
                    $adj[$j][] = $i;
                }
            }
        }

        $visited = array_fill(0, $n, false);
        $clusters = [];

        for ($i = 0; $i < $n; $i++) {
            if ($visited[$i]) {
                continue;
            }

            $stack = [$i];
            $visited[$i] = true;
            $component = [];

            while ($stack !== []) {
                $u = (int) array_pop($stack);
                $component[] = $entries[$u]['id'];
                foreach ($adj[$u] as $v) {
                    if (! $visited[$v]) {
                        $visited[$v] = true;
                        $stack[] = $v;
                    }
                }
            }

            if (count($component) > 1) {
                $clusters[] = $component;
            }
        }

        return $clusters;
    }
}
