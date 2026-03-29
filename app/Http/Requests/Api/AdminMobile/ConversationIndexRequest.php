<?php

namespace App\Http\Requests\Api\AdminMobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConversationIndexRequest extends FormRequest
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
            'scope' => ['nullable', 'string', Rule::in([
                'all',
                'unread',
                'bot_active',
                'human_takeover',
                'escalated',
                'closed',
                'booking_in_progress',
            ])],
            'channel' => ['nullable', 'string', Rule::in([
                'all',
                'whatsapp',
                'mobile_live_chat',
            ])],
            'search' => ['nullable', 'string', 'max:255'],
            'selected_conversation_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort_by' => ['nullable', 'string', Rule::in([
                'last_message_at',
                'started_at',
                'created_at',
                'updated_at',
            ])],
            'sort_dir' => ['nullable', 'string', Rule::in([
                'asc',
                'desc',
            ])],
        ];
    }
}
