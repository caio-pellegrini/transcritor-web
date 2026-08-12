<?php

use App\Jobs\VerifyQueueWorkerJob;
use Illuminate\Support\Facades\Storage;

test('health endpoint is available', function () {
    $this->get('/up')->assertOk();
});

test('sqlite concurrency settings are configured', function () {
    expect(app()->environment())->toBe('testing')
        ->and(config('session.driver'))->toBe('array')
        ->and(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.busy_timeout'))->toBe(10000)
        ->and(config('database.connections.sqlite.journal_mode'))->toBe('WAL')
        ->and(config('database.connections.sqlite.synchronous'))->toBe('NORMAL')
        ->and(config('database.connections.sqlite.transaction_mode'))->toBe('DEFERRED');
});

test('queue diagnostic job writes its marker without a database model', function () {
    Storage::fake('local');

    (new VerifyQueueWorkerJob)->handle();

    Storage::disk('local')->assertExists('diagnostics/queue-worker.json');
});
