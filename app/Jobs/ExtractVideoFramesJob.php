<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ImportedMedia;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class ExtractVideoFramesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public string $importedMediaId,
    ) {
        $this->timeout = 300;
    }

    public function handle(): void
    {
        $media = ImportedMedia::query()->find($this->importedMediaId);
        if ($media === null || $media->local_path === null) {
            return;
        }

        $videoPath = Storage::disk('media')->path($media->local_path);
        if (! is_readable($videoPath)) {
            return;
        }

        $ffmpeg = $this->ffmpegBinary();
        if ($ffmpeg === null) {
            return;
        }

        $frameDir = 'frames/'.$media->id;
        Storage::disk('media')->makeDirectory($frameDir);

        $pattern = Storage::disk('media')->path($frameDir.'/frame_%03d.jpg');

        $process = new Process([
            $ffmpeg,
            '-y',
            '-i',
            $videoPath,
            '-vf',
            'fps=1',
            $pattern,
        ]);
        $process->setTimeout(280);

        try {
            $process->mustRun();
        } catch (Throwable) {
            return;
        }

        $paths = Storage::disk('media')->files($frameDir);
        sort($paths);
        $media->frames = array_values($paths);
        $media->save();
    }

    private function ffmpegBinary(): ?string
    {
        foreach (['ffmpeg', '/usr/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'] as $candidate) {
            if ($candidate === 'ffmpeg') {
                $process = new Process(['which', 'ffmpeg']);
                $process->run();
                $path = trim($process->getOutput());

                return $path !== '' ? $path : null;
            }

            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
