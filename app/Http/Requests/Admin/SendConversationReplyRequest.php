<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
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
            'message_type' => ['nullable', 'string', Rule::in(['text', 'audio', 'image'])],

            'message' => [
                'nullable',
                'string',
                'min:1',
                'max:4096',
                'required_if:message_type,text',
            ],

            'audio_url' => [
                'nullable',
                'url',
                'max:2048',
                'required_without:audio_file',
            ],

            'audio_file' => [
                'nullable',
                'file',
                'max:16384',
                'mimetypes:audio/ogg,audio/opus,application/ogg,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac',
            ],

            'image_file' => [
                'nullable',
                'file',
                'image',
                'max:5120',
                'required_if:message_type,image',
            ],

            'mime_type' => ['nullable', 'string', 'max:120'],
            'voice' => ['nullable', 'boolean'],
            'caption' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required_if' => 'Pesan tidak boleh kosong.',
            'message.max' => 'Pesan terlalu panjang (maksimal 4096 karakter).',

            'audio_url.required_without' => 'Voice note wajib diisi melalui file atau URL.',
            'audio_url.url' => 'URL voice note tidak valid.',

            'audio_file.file' => 'Voice note harus berupa file audio.',
            'audio_file.max' => 'Ukuran voice note maksimal 16 MB.',
            'audio_file.mimetypes' => 'Format voice note tidak didukung. Gunakan OGG/OPUS, MP3, atau M4A.',

            'image_file.required_if' => 'Gambar wajib dipilih dari galeri.',
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

        $this->merge($payload);
    }

    private function normalizeTextInput(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }
}