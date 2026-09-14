<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class UsdBrlExchangeRateService
{
    private const CACHE_KEY = 'exchange-rate.usd-brl.v1';

    /**
     * @return array{rate: float, quoted_at: string, source: string}
     */
    public function current(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            (int) config('transcription.exchange_rate.cache_seconds'),
            fn (): array => $this->fetchOrFallback(),
        );
    }

    /**
     * @return array{rate: float, quoted_at: string, source: string}
     */
    private function fetchOrFallback(): array
    {
        $endpoints = [
            ['url' => config('transcription.exchange_rate.endpoint'), 'source' => 'awesomeapi'],
            ['url' => config('transcription.exchange_rate.fallback_endpoint'), 'source' => 'open-er-api'],
        ];

        foreach ($endpoints as $endpoint) {
            try {
                $request = Http::acceptJson()->retry(2, 200)->connectTimeout(3)->timeout(5);

                if ($endpoint['source'] === 'awesomeapi' && filled(config('transcription.exchange_rate.api_key'))) {
                    $request = $request->withHeader(
                        'x-api-key',
                        (string) config('transcription.exchange_rate.api_key'),
                    );
                }

                $response = $request
                    ->get($endpoint['url'])->throw();
                $bid = $endpoint['source'] === 'awesomeapi'
                    ? $response->json('USDBRL.bid') : $response->json('rates.BRL');
                $timestamp = $endpoint['source'] === 'awesomeapi'
                    ? $response->json('USDBRL.timestamp') : $response->json('time_last_update_unix');

                if (! is_numeric($bid) || (float) $bid <= 0) {
                    throw new RuntimeException('Invalid exchange-rate response.');
                }

                return [
                    'rate' => (float) $bid,
                    'quoted_at' => is_numeric($timestamp)
                        ? CarbonImmutable::createFromTimestampUTC((int) $timestamp)->setTimezone(config('app.timezone'))->toIso8601String()
                        : now()->toIso8601String(),
                    'source' => $endpoint['source'],
                ];
            } catch (Throwable $exception) {
                Log::warning('Exchange rate provider failed.', ['endpoint' => $endpoint['url'], 'message' => $exception->getMessage()]);
            }
        }

        return $this->fallback();
    }

    /**
     * @return array{rate: float, quoted_at: string, source: string}
     */
    private function fallback(): array
    {
        $fallbackRate = (float) config('transcription.exchange_rate.fallback');

        return [
            'rate' => $fallbackRate > 0 ? $fallbackRate : 5.0,
            'quoted_at' => now()->toIso8601String(),
            'source' => 'fallback',
        ];
    }
}
