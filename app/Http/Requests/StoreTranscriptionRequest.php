<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreTranscriptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $extensions = config('transcription.accepted_extensions');
        $maxKilobytes = (int) floor(config('transcription.limits.max_upload_bytes') / 1024);

        return [
            'media' => [
                'required',
                File::types($extensions)->max($maxKilobytes),
                'extensions:'.implode(',', $extensions),
            ],
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
                if (! $this->boolean('diarization')) {
                    return;
                }

                $supportsDiarization = config(
                    "transcription.providers.{$this->string('provider')}.models.{$this->string('model')}.capabilities.diarization",
                    false,
                );

                if (! $supportsDiarization) {
                    $validator->errors()->add('diarization', 'O modelo selecionado não oferece diarização.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $file = $this->file('media');
        $uploadError = $file instanceof UploadedFile ? $file->getError() : null;
        $uploadFailureMessage = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'O arquivo excede o limite de 500 MB.'
            : 'Não foi possível receber o arquivo. Verifique o espaço livre no servidor e tente novamente.';

        return [
            'media.required' => 'Selecione um arquivo de áudio ou vídeo.',
            'media.uploaded' => $uploadFailureMessage,
            'media.max' => 'O arquivo excede o limite de 500 MB.',
            'media.extensions' => 'A extensão do arquivo não é aceita.',
            'media.mimetypes' => 'O tipo do arquivo não é aceito.',
            'provider.in' => 'O provider selecionado é inválido.',
            'model.in' => 'O modelo selecionado não pertence ao provider informado.',
        ];
    }
}
