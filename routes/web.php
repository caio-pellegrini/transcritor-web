<?php

use App\Http\Controllers\TranscriptionController;
use App\Http\Controllers\TranscriptionExportController;
use App\Http\Controllers\TranscriptionStatusController;
use App\Http\Controllers\TranscriptionWorkspaceController;
use App\Http\Controllers\UnlockController;
use Illuminate\Support\Facades\Route;

Route::get('/unlock', [UnlockController::class, 'show'])->name('unlock.show');
Route::post('/unlock', [UnlockController::class, 'store'])
    ->middleware('throttle:unlock')
    ->name('unlock.store');

Route::middleware('transcription.unlocked')->group(function (): void {
    Route::get('/', TranscriptionWorkspaceController::class)->name('transcriptions.index');
    Route::post('/transcriptions', [TranscriptionController::class, 'store'])->name('transcriptions.store');
    Route::get('/transcriptions/{transcription}', TranscriptionWorkspaceController::class)->name('transcriptions.show');
    Route::patch('/transcriptions/{transcription}/estimate', [TranscriptionController::class, 'updateEstimate'])
        ->name('transcriptions.estimate');
    Route::post('/transcriptions/{transcription}/start', [TranscriptionController::class, 'start'])
        ->name('transcriptions.start');
    Route::get('/transcriptions/{transcription}/status', TranscriptionStatusController::class)
        ->name('transcriptions.status');
    Route::get('/transcriptions/{transcription}/export.html', [TranscriptionExportController::class, 'html'])
        ->name('transcriptions.export.html');
    Route::get('/transcriptions/{transcription}/export.docx', [TranscriptionExportController::class, 'docx'])
        ->name('transcriptions.export.docx');
});
