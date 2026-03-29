<?php

namespace App\Http\Requests\Api\Mobile;

use Illuminate\Foundation\Http\FormRequest;

class StartConversationRequest extends FormRequest
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
            'source_app' => ['nullable', 'string', 'max:80'],
            'opening_message' => ['nullable', 'string', 'max:4000'],
            'client_message_id' => ['nullable', 'string', 'max:150'],
        ];
    }
}
