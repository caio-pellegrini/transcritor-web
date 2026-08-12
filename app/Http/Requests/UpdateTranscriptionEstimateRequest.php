<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTranscriptionEstimateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(array_keys(config('transcription.providers')))],
            'model' => [
                'required',
                'string',
                Rule::in(array_keys(config("transcription.providers.{$this->string('provider')}.models", []))),
            ],
            'diarization' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->boolean('diarization') && ! config(
                    "transcription.providers.{$this->string('provider')}.models.{$this->string('model')}.capabilities.diarization",
                    false,
                )) {
                    $validator->errors()->add('diarization', 'O modelo selecionado não oferece diarização.');
                }
            },
        ];
    }
}
