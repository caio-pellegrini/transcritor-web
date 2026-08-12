<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class VerifyQueueWorkerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Storage::disk('local')->put('diagnostics/queue-worker.json', json_encode([
            'processed_at' => now()->toIso8601String(),
            'hostname' => gethostname(),
        ], JSON_THROW_ON_ERROR));
    }
}
