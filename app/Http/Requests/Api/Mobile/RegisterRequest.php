<?php

namespace App\Http\Requests\Api\Mobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
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
            'mobile_user_id' => ['nullable', 'string', 'max:100'],
            'device_id' => ['required', 'string', 'max:150'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'avatar_url' => ['nullable', 'url', 'max:2048'],
            'preferred_channel' => ['nullable', 'string', Rule::in(['mobile_live_chat', 'whatsapp'])],
        ];
    }
}
