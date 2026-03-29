<?php

namespace App\Http\Requests\Api\AdminMobile;

use App\Http\Requests\Api\AdminMobile\Concerns\InteractsWithAdminMobileActor;
use Illuminate\Foundation\Http\FormRequest;

class MarkConversationReadRequest extends FormRequest
{
    use InteractsWithAdminMobileActor;

    public function authorize(): bool
    {
        return $this->canAccessConversationActions();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
