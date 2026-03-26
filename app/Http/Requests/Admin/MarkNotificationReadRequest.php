<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MarkNotificationReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Access controlled by chatbot.admin middleware on the route.
    }

    public function rules(): array
    {
        return []; // No user-submitted data — the notification is resolved via route model binding.
    }
}
