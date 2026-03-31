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
            'message_type' => ['nullable', 'string', Rule::in(['text', 'audio'])],
            'message' => ['nullable', 'string', 'min:1', 'max:4096', 'required_if:message_type,text'],
            'audio_url' => ['nullable', 'url', 'max:2048', 'required_if:message_type,audio'],
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
            'audio_url.required_if' => 'URL voice note wajib diisi.',
            'audio_url.url' => 'URL voice note tidak valid.',
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

        return trim((string) $value);
    }
}
