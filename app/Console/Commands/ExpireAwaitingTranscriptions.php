<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class ExpireAwaitingTranscriptions extends Command
{
    protected $signature = 'transcriptions:expire';

    protected $description = 'Expira transcrições não confirmadas e remove sua mídia';

    public function handle(): int
    {
        $expired = 0;
        $legacyCutoff = now()->subHours(
            (int) config('transcription.cleanup.awaiting_confirmation_hours'),
        );

        Transcription::query()
            ->where('status', Transcription::STATUS_AWAITING_CONFIRMATION)
            ->where(function (Builder $query) use ($legacyCutoff): void {
                $query
                    ->where('expires_at', '<=', now())
                    ->orWhere(function (Builder $query) use ($legacyCutoff): void {
                        $query->whereNull('expires_at')->where('created_at', '<=', $legacyCutoff);
                    });
            })
            ->chunkById(100, function ($transcriptions) use (&$expired): void {
                foreach ($transcriptions as $transcription) {
                    $claimed = Transcription::query()
                        ->whereKey($transcription->getKey())
                        ->where('status', Transcription::STATUS_AWAITING_CONFIRMATION)
                        ->update([
                            'status' => Transcription::STATUS_EXPIRED,
                            'progress_stage' => 'expired',
                            'finished_at' => now(),
                            'updated_at' => now(),
                        ]) === 1;

                    if (! $claimed) {
                        continue;
                    }

                    Storage::disk('local')->deleteDirectory(
                        "transcriptions/{$transcription->getKey()}",
                    );

                    $expired++;
                }
            });

        $this->info("Transcrições expiradas: {$expired}");

        return self::SUCCESS;
    }
}
