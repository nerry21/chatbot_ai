<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SendConversationReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message_type' => [
                'nullable',
                'string',
                Rule::in(['text', 'audio', 'image']),
            ],

            'message' => [
                'nullable',
                'string',
                'min:1',
                'max:4096',
            ],

            'audio_url' => [
                'nullable',
                'url',
                'max:2048',
            ],

            'audio_file' => [
                'nullable',
                'file',
                'max:16384',
            ],

            'image_file' => [
                'nullable',
                'file',
                'image',
                'max:5120',
            ],

            'mime_type' => ['nullable', 'string', 'max:120'],
            'voice' => ['nullable', 'boolean'],
            'caption' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.max' => 'Pesan terlalu panjang (maksimal 4096 karakter).',
            'audio_url.url' => 'URL voice note tidak valid.',
            'audio_file.file' => 'Voice note harus berupa file.',
            'audio_file.max' => 'Ukuran voice note maksimal 16 MB.',
            'image_file.image' => 'File yang dipilih harus berupa gambar.',
            'image_file.max' => 'Ukuran gambar maksimal 5 MB.',
            'message_type.in' => 'Jenis pesan tidak didukung.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $messageType = strtolower(trim((string) $this->input('message_type', 'text')));
        $message = $this->normalizeTextInput($this->input('message'));
        $caption = $this->normalizeTextInput($this->input('caption'));
        $audioFile = $this->file('audio_file');
        $normalizedMimeType = $this->normalizeAudioMimeType(
            $audioFile instanceof UploadedFile ? $audioFile : null,
            $this->input('mime_type'),
        );

        $payload = [
            'message_type' => $messageType === '' ? 'text' : $messageType,
            'voice' => filter_var($this->input('voice', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($caption !== null) {
            $payload['caption'] = $caption;
        }

        if ($normalizedMimeType !== null) {
            $payload['mime_type'] = $normalizedMimeType;
        }

        $this->merge($payload);

        Log::info('VOICE_NOTE prepareForValidation', [
            'message_type' => $this->input('message_type'),
            'has_audio_file' => $this->hasFile('audio_file'),
            'audio_file_name' => $audioFile?->getClientOriginalName(),
            'audio_file_client_mime' => $audioFile?->getClientMimeType(),
            'audio_file_detected_mime' => $audioFile?->getMimeType(),
            'audio_file_size' => $audioFile?->getSize(),
            'audio_url' => $this->input('audio_url'),
            'mime_type' => $this->input('mime_type'),
            'normalized_mime_type' => $normalizedMimeType,
            'voice' => $this->input('voice'),
            'caption' => $this->input('caption'),
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $messageType = (string) $this->input('message_type', 'text');

            if ($messageType === 'text') {
                $message = $this->normalizeTextInput($this->input('message'));

                if ($message === null) {
                    $validator->errors()->add('message', 'Pesan tidak boleh kosong.');
                }

                return;
            }

            if ($messageType === 'audio') {
                $hasAudioFile = $this->hasFile('audio_file');
                $audioUrl = trim((string) $this->input('audio_url', ''));

                if (! $hasAudioFile && $audioUrl === '') {
                    $validator->errors()->add('audio_file', 'Voice note wajib diisi melalui file atau URL.');
                }

                return;
            }

            if ($messageType === 'image') {
                if (! $this->hasFile('image_file')) {
                    $validator->errors()->add('image_file', 'Gambar wajib dipilih dari galeri.');
                }
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        $audioFile = $this->file('audio_file');

        Log::warning('VOICE_NOTE validation_failed', [
            'errors' => $validator->errors()->toArray(),
            'message_type' => $this->input('message_type'),
            'has_audio_file' => $this->hasFile('audio_file'),
            'audio_file_name' => $audioFile?->getClientOriginalName(),
            'audio_file_client_mime' => $audioFile?->getClientMimeType(),
            'audio_file_detected_mime' => $audioFile?->getMimeType(),
            'audio_file_size' => $audioFile?->getSize(),
            'all_input_keys' => array_keys($this->all()),
        ]);

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validasi request gagal.',
            'errors' => $validator->errors(),
        ], 422));
    }

    private function normalizeTextInput(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function normalizeAudioMimeType(?UploadedFile $audioFile, mixed $inputMimeType): ?string
    {
        $candidates = array_filter([
            is_string($inputMimeType) ? trim($inputMimeType) : null,
            $audioFile?->getClientMimeType(),
            $audioFile?->getMimeType(),
        ], static fn (?string $value): bool => is_string($value) && trim($value) !== '');

        $extension = strtolower(trim((string) pathinfo($audioFile?->getClientOriginalName() ?? '', PATHINFO_EXTENSION)));

        if (in_array($extension, ['ogg', 'opus'], true)) {
            return 'audio/ogg';
        }

        if (in_array($extension, ['m4a', 'mp4', 'aac'], true)) {
            return 'audio/mp4';
        }

        if ($extension === 'mp3') {
            return 'audio/mpeg';
        }

        foreach ($candidates as $candidate) {
            $normalized = strtolower(trim($candidate));

            return match ($normalized) {
                'audio/ogg', 'audio/opus', 'application/ogg' => 'audio/ogg',
                'audio/mp3', 'audio/mpeg' => 'audio/mpeg',
                'audio/x-m4a', 'audio/mp4', 'video/mp4', 'audio/aac' => 'audio/mp4',
                default => $normalized !== '' ? $normalized : null,
            };
        }

        return null;
    }
}
