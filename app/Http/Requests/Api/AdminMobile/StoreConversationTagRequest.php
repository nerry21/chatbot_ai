<?php

namespace App\Http\Requests\Api\AdminMobile;

use App\Http\Requests\Api\AdminMobile\Concerns\InteractsWithAdminMobileActor;
use Illuminate\Foundation\Http\FormRequest;

class StoreConversationTagRequest extends FormRequest
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
        return [
            'tag' => ['required', 'string', 'min:2', 'max:40', 'regex:/[A-Za-z0-9]/'],
            'target' => ['required', 'in:conversation,customer'],
        ];
    }
}
