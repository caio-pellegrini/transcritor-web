<?php

namespace App\Actions;

use App\Models\Transcription;
use App\Services\UsdBrlExchangeRateService;
use InvalidArgumentException;

class EstimateTranscription
{
    public function __construct(
        private readonly UsdBrlExchangeRateService $exchangeRate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Transcription $transcription): array
    {
        $modelConfig = config(
            "transcription.providers.{$transcription->provider}.models.{$transcription->model}",
        );

        if (! is_array($modelConfig)) {
            throw new InvalidArgumentException('Unknown transcription model.');
        }

        $pricing = $modelConfig['pricing'];
        $durationSeconds = $transcription->duration_seconds;

        $costUsd = match ($pricing['type']) {
            'per_minute' => max($durationSeconds / 60, $pricing['minimum_minutes']) * $pricing['usd'],
            'per_hour' => ($durationSeconds / 3600) * $pricing['usd'],
            default => throw new InvalidArgumentException('Unknown pricing type.'),
        };

        $quote = $this->exchangeRate->current();

        return [
            'cost_usd' => round($costUsd, 6),
            'cost_brl' => round($costUsd * $quote['rate'], 6),
            'exchange_rate' => $quote['rate'],
            'exchange_rate_quoted_at' => $quote['quoted_at'],
            'exchange_rate_source' => $quote['source'],
            'approximate_processing_seconds' => (int) ceil(($durationSeconds / 60) * 5 * 1.2),
            'pricing_notice' => config("transcription.providers.{$transcription->provider}.pricing_notice"),
        ];
    }
}
