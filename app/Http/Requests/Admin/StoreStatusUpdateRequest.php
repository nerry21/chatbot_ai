<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStatusUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status_type' => [
                'required',
                'string',
                Rule::in(['text', 'image', 'video', 'audio', 'music']),
            ],
            'text' => [
                'nullable',
                'string',
                'max:1500',
            ],
            'caption' => [
                'nullable',
                'string',
                'max:500',
            ],
            'background_color' => [
                'nullable',
                'string',
                'max:20',
            ],
            'text_color' => [
                'nullable',
                'string',
                'max:20',
            ],
            'font_style' => [
                'nullable',
                'string',
                'max:50',
            ],
            'music_title' => [
                'nullable',
                'string',
                'max:200',
            ],
            'music_artist' => [
                'nullable',
                'string',
                'max:200',
            ],
            'media_file' => [
                'nullable',
                'file',
                'max:51200',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = (string) $this->input('status_type', '');

            if ($type === 'text' && blank($this->input('text'))) {
                $validator->errors()->add('text', 'Teks status wajib diisi.');
            }

            if (in_array($type, ['image', 'video', 'audio'], true) && ! $this->hasFile('media_file')) {
                $validator->errors()->add('media_file', 'File media wajib diunggah.');
            }

            if ($type === 'music' && blank($this->input('music_title')) && blank($this->input('text'))) {
                $validator->errors()->add('music_title', 'Judul musik atau teks status wajib diisi.');
            }
        });
    }
}
