<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SendConversationContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:120'],
            'company' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'Nama kontak wajib diisi.',
            'phone.required' => 'Nomor telepon kontak wajib diisi.',
            'email.email' => 'Format email kontak tidak valid.',
        ];
    }
}
