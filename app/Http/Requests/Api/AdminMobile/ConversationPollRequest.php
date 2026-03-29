<?php

namespace App\Http\Requests\Api\AdminMobile;

use Illuminate\Foundation\Http\FormRequest;

class ConversationPollRequest extends FormRequest
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
            'after_message_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
