<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Transcription extends Model
{
    use HasUlids;

    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'status',
        'progress_stage',
        'progress_current',
        'progress_total',
        'error_message',
        'text',
        'language',
        'segments',
        'original_filename',
        'media_path',
        'extension',
        'mime_type',
        'size_bytes',
        'duration_seconds',
        'provider',
        'model',
        'diarization',
        'expires_at',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'media_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'diarization' => 'boolean',
            'duration_seconds' => 'float',
            'size_bytes' => 'integer',
            'progress_current' => 'integer',
            'progress_total' => 'integer',
            'expires_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'segments' => 'array',
        ];
    }
}
