<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InspirationStatusEnum;
use App\Models\Inspiration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessImportedMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $inspirationId,
    ) {}

    public function handle(): void
    {
        $inspiration = Inspiration::query()->find($this->inspirationId);
        if ($inspiration === null) {
            return;
        }

        // Placeholder : métadonnées déjà renseignées en synchrone ; ici transcodage / miniatures supplémentaires.
        if ($inspiration->status === InspirationStatusEnum::Pending) {
            $inspiration->status = InspirationStatusEnum::Processed;
            $inspiration->save();
        }
    }
}
