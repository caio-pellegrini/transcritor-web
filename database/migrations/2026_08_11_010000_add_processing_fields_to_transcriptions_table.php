<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->string('progress_stage')->nullable()->after('status');
            $table->unsignedInteger('progress_current')->nullable()->after('progress_stage');
            $table->unsignedInteger('progress_total')->nullable()->after('progress_current');
            $table->string('error_message')->nullable()->after('progress_total');
            $table->timestamp('expires_at')->nullable()->index()->after('diarization');
            $table->timestamp('queued_at')->nullable()->after('expires_at');
            $table->timestamp('started_at')->nullable()->after('queued_at');
            $table->timestamp('finished_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn([
                'progress_stage',
                'progress_current',
                'progress_total',
                'error_message',
                'expires_at',
                'queued_at',
                'started_at',
                'finished_at',
            ]);
        });
    }
};
