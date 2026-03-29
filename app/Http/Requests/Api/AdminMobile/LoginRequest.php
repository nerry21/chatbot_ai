<?php

namespace App\Http\Requests\Api\AdminMobile;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:1'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'device_id' => ['nullable', 'string', 'max:150'],
        ];
    }
}
