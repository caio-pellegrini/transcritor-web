<?php

$acceptedExtensions = ['mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'ogg', 'wav', 'webm'];

return [
    'accepted_extensions' => $acceptedExtensions,

    'format_names_by_extension' => [
        'mp3' => ['mp3'],
        'mp4' => ['mov', 'mp4', 'm4a', '3gp', '3g2', 'mj2'],
        'mpeg' => ['mpeg'],
        'mpga' => ['mp3'],
        'm4a' => ['mov', 'mp4', 'm4a', '3gp', '3g2', 'mj2'],
        'wav' => ['wav'],
        'ogg' => ['ogg'],
        'webm' => ['matroska', 'webm'],
    ],

    'exchange_rate' => [
        'endpoint' => 'https://economia.awesomeapi.com.br/json/last/USD-BRL',
        'fallback_endpoint' => 'https://open.er-api.com/v6/latest/USD',
        'api_key' => env('AWESOMEAPI_KEY'),
        'cache_seconds' => 24 * 60 * 60,
        'fallback' => (float) env('USD_BRL_FALLBACK_RATE', 5.0),
    ],

    'limits' => [
        'max_upload_bytes' => 500 * 1024 * 1024,
        'max_duration_seconds' => 4 * 60 * 60,
    ],

    'cleanup' => [
        'awaiting_confirmation_hours' => 24,
    ],

    'storage' => [
        'minimum_free_bytes' => max(
            0,
            (int) env('TRANSCRIPTION_MIN_FREE_DISK_MB', 512),
        ) * 1024 * 1024,
    ],

    'providers' => [
        'openai' => [
            'label' => 'OpenAI',
            'display_order' => 2,
            'endpoint' => 'https://api.openai.com/v1/audio/transcriptions',
            'constraints' => [
                'max_file_size_bytes' => 25 * 1024 * 1024,
                'transcode_target_bytes' => 23 * 1024 * 1024,
                'transcode_min_bitrate_kbps' => 16,
                'transcode_max_bitrate_kbps' => 64,
                'accepted_extensions' => $acceptedExtensions,
            ],
            'models' => [
                'gpt-transcribe' => [
                    'label' => 'GPT-Transcribe',
                    'display_order' => 1,
                    'capabilities' => ['diarization' => false],
                    'constraints' => ['max_duration_seconds' => null],
                    'pricing' => [
                        'type' => 'per_minute',
                        'usd' => 0.0045,
                        'minimum_minutes' => 1.0,
                    ],
                ],
                'whisper-1' => [
                    'label' => 'Whisper 1',
                    'display_order' => 2,
                    'capabilities' => ['diarization' => false],
                    'constraints' => ['max_duration_seconds' => null],
                    'pricing' => [
                        'type' => 'per_minute',
                        'usd' => 0.006,
                        'minimum_minutes' => 1.0,
                    ],
                ],
            ],
            'pricing_notice' => 'Estimativa pelo preço configurado em tabela; o valor efetivamente cobrado pode variar.',
        ],

        'elevenlabs' => [
            'label' => 'ElevenLabs',
            'display_order' => 1,
            'endpoint' => 'https://api.elevenlabs.io/v1/speech-to-text',
            'constraints' => [
                'max_file_size_bytes' => 3 * 1024 * 1024 * 1024,
                'max_duration_seconds' => 10 * 60 * 60,
                'accepted_extensions' => $acceptedExtensions,
            ],
            'models' => [
                'scribe_v2' => [
                    'label' => 'Scribe v2',
                    'display_order' => 1,
                    'capabilities' => ['diarization' => true],
                    'pricing' => [
                        'type' => 'per_hour',
                        'usd' => 0.22,
                        'minimum_hours' => null,
                    ],
                ],
            ],
            'pricing_notice' => 'Estimativa sem cobrança mínima ou arredondamento, pois essas regras não foram confirmadas.',
        ],
    ],

    'processing' => [
        'ffmpeg_timeout_seconds' => 60 * 60,
        'provider_connect_timeout_seconds' => 10,
        'provider_timeout_seconds' => 6 * 60 * 60,
    ],
];
