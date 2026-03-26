<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ResendConversationMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Access already gated by chatbot.admin middleware on the route
    }

    public function rules(): array
    {
        return [];
        // No user-submitted fields required for resend — the message is resolved
        // from the route model binding and all validation is done in the controller.
    }
}
