<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->get(config('transcription.exchange_rate.endpoint'))
                ->throw();

            $bid = $response->json('USDBRL.bid');
            $timestamp = $response->json('USDBRL.timestamp');

            if (! is_numeric($bid) || (float) $bid <= 0 || ! is_numeric($timestamp)) {
                throw new RuntimeException('Invalid exchange-rate response.');
            }

            return [
                'rate' => (float) $bid,
                'quoted_at' => CarbonImmutable::createFromTimestampUTC((int) $timestamp)
                    ->setTimezone(config('app.timezone'))
                    ->toIso8601String(),
                'source' => 'awesomeapi',
            ];
        } catch (Throwable) {
            return $this->fallback();
        }
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
