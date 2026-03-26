<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MarkAllNotificationsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Access controlled by chatbot.admin middleware on the route.
    }

    public function rules(): array
    {
        return []; // No user-submitted data — bulk mark-read has no input payload.
    }
}
