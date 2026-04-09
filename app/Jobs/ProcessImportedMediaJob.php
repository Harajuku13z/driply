<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Models\ImportedMedia;
use App\Services\FastServerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class ProcessImportedMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $importedMediaId,
    ) {
        $this->timeout = 300;
    }

    public function handle(FastServerService $fastServer): void
    {
        $media = ImportedMedia::query()->find($this->importedMediaId);
        if ($media === null) {
            return;
        }

        $media->status = MediaStatus::Processing;
        $media->save();

        try {
            if ($media->local_path === null || $media->local_path === '') {
                $ext = $media->type === MediaType::Image ? 'jpg' : 'mp4';
                $path = 'imports/'.Str::uuid()->toString().'.'.$ext;
                $payload = $fastServer->fetchMedia($media->source_url, $media->platform->value);
                $fastServer->downloadMedia($payload['download_url'], $path, 'media');
                $media->local_path = $path;
                $media->thumbnail_url = $payload['thumbnail_url'] ?? $media->thumbnail_url;
                $media->title = $payload['title'] ?? $media->title;
                $media->duration_seconds = $payload['duration'] ?? $media->duration_seconds;
            }

            $media->status = MediaStatus::Processed;
            $media->error_message = null;
            $media->save();

            if ($media->type === MediaType::Video) {
                ExtractVideoFramesJob::dispatch($media->id);
            }
        } catch (Throwable $e) {
            $media->status = MediaStatus::Failed;
            $media->error_message = $e->getMessage();
            $media->save();

            throw $e;
        }
    }
}
