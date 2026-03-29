<?php

namespace App\Http\Requests\Api\AdminMobile;

use App\Http\Requests\Api\AdminMobile\Concerns\InteractsWithAdminMobileActor;
use Illuminate\Foundation\Http\FormRequest;

class CloseConversationStatusRequest extends FormRequest
{
    use InteractsWithAdminMobileActor;

    public function authorize(): bool
    {
        return $this->isFullAdmin();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
