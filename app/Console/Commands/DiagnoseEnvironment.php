<?php

namespace App\Console\Commands;

use App\Jobs\VerifyQueueWorkerJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

#[Signature('app:diagnose {--queue : Dispatch a marker job to the database queue}')]
#[Description('Check PHP, ffmpeg, ffprobe, SQLite and optionally the queue worker')]
class DiagnoseEnvironment extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $binariesAreAvailable = $this->checkBinaries();

        $this->table(['SQLite setting', 'Value'], [
            ['journal_mode', $this->pragmaValue('journal_mode')],
            ['busy_timeout', $this->pragmaValue('busy_timeout')],
            ['synchronous', $this->pragmaValue('synchronous')],
        ]);

        if ($this->option('queue')) {
            Storage::disk('local')->delete('diagnostics/queue-worker.json');
            VerifyQueueWorkerJob::dispatch();
            $this->info('Queue marker dispatched. The worker writes storage/app/private/diagnostics/queue-worker.json.');
        }

        return $binariesAreAvailable ? self::SUCCESS : self::FAILURE;
    }

    private function checkBinaries(): bool
    {
        $checks = collect(['ffmpeg', 'ffprobe'])->map(function (string $binary): array {
            $result = Process::timeout(10)->run([$binary, '-version']);
            $version = str($result->output())->before("\n")->toString();

            return [$binary, $result->successful() ? $version : 'not available', $result->successful()];
        });

        $this->table(['Binary', 'Version'], [
            ['php', PHP_VERSION],
            ...$checks->map(fn (array $check): array => [$check[0], $check[1]])->all(),
        ]);

        return $checks->every(fn (array $check): bool => $check[2]);
    }

    private function pragmaValue(string $pragma): string
    {
        $result = DB::selectOne("PRAGMA {$pragma}");

        return (string) array_values((array) $result)[0];
    }
}
