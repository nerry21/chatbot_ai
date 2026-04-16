<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdminMobileContactRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'sync_to_device' => ['nullable', 'boolean'],
            'country_code' => ['nullable', 'string', 'max:5'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'Nama depan wajib diisi.',
            'phone.required' => 'Nomor telepon wajib diisi.',
            'email.email' => 'Format email tidak valid.',
        ];
    }
}