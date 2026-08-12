<?php

use App\Jobs\ProcessTranscriptionJob;
use Tests\TestCase;

uses(TestCase::class);

test('upload limits are aligned while preserving multipart overhead', function () {
    $nginx = file_get_contents(__DIR__.'/../../docker/nginx/default.conf');
    $php = file_get_contents(__DIR__.'/../../docker/php/uploads.ini');

    expect(config('transcription.limits.max_upload_bytes'))
        ->toBe(500 * 1024 * 1024)
        ->and($nginx)->toContain('client_max_body_size 510M;')
        ->and($php)->toContain('upload_max_filesize=500M')
        ->and($php)->toContain('post_max_size=510M');
});

test('request provider and queue timeouts have ordered headroom', function () {
    $nginx = file_get_contents(__DIR__.'/../../docker/nginx/default.conf');
    $php = file_get_contents(__DIR__.'/../../docker/php/uploads.ini');
    $fpm = file_get_contents(__DIR__.'/../../docker/php/fpm-pool.conf');
    $supervisor = file_get_contents(__DIR__.'/../../docker/supervisor/worker.conf');
    $job = new ProcessTranscriptionJob('test', 'encrypted');
    $providerTimeout = (int) config('transcription.processing.provider_timeout_seconds');
    $retryAfter = (int) config('queue.connections.database.retry_after');

    expect($nginx)
        ->toContain('client_body_timeout 7200s;')
        ->toContain('fastcgi_send_timeout 7200s;')
        ->toContain('fastcgi_read_timeout 7200s;')
        ->and($php)->toContain('max_input_time=7200')
        ->and($fpm)->toContain('request_terminate_timeout = 7200s')
        ->and($providerTimeout)->toBe(21600)
        ->and($job->timeout)->toBe(25200)
        ->and($job->tries)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($retryAfter)->toBe(25500)
        ->and($providerTimeout)->toBeLessThan($job->timeout)
        ->and($job->timeout)->toBeLessThan($retryAfter)
        ->and($supervisor)
        ->toContain('--tries=1 --timeout=25200 --max-time=43200')
        ->toContain('stopwaitsecs=25300');
});

test('production compose keeps its http port private and bounds container logs', function () {
    $compose = file_get_contents(__DIR__.'/../../docker-compose.yml');

    expect($compose)
        ->toContain('127.0.0.1:${APP_PORT:-8000}:80')
        ->toContain('stop_grace_period: 7h5m')
        ->toContain('app_storage:/var/www/html/storage')
        ->and(substr_count($compose, 'healthcheck:'))->toBe(3)
        ->and(substr_count($compose, 'max-size: "10m"'))->toBe(3)
        ->and(substr_count($compose, 'max-file: "3"'))->toBe(3);
});

test('production php fpm concurrency is deliberately small for the vps', function () {
    $fpm = file_get_contents(__DIR__.'/../../docker/php/fpm-pool.conf');

    expect($fpm)
        ->toContain('pm.max_children = 2')
        ->toContain('pm.max_spare_servers = 1');
});

test('production defaults require secure cookies and rotate application logs', function () {
    $environment = file_get_contents(__DIR__.'/../../.env.example');
    $bootstrap = file_get_contents(__DIR__.'/../../bootstrap/app.php');
    $provider = file_get_contents(__DIR__.'/../../app/Providers/AppServiceProvider.php');

    expect($environment)
        ->toContain('APP_DEBUG=false')
        ->toContain('SESSION_SECURE_COOKIE=true')
        ->toContain('SESSION_HTTP_ONLY=true')
        ->toContain('LOG_CHANNEL=daily')
        ->toContain('LOG_DAILY_DAYS=14')
        ->and($bootstrap)->toContain("trustProxies(at: '*')")
        ->and($provider)->toContain("URL::forceScheme('https')");
});
