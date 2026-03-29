<?php

namespace App\Http\Resources\AdminMobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->isChatbotAdmin()
            ? 'admin'
            : ($this->isChatbotOperator() ? 'operator' : 'user');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $role,
            'is_chatbot_admin' => $this->isChatbotAdmin(),
            'is_chatbot_operator' => $this->isChatbotOperator(),
            'can_access_chatbot_admin' => $this->canAccessChatbotAdmin(),
            'permissions' => [
                'can_read_workspace' => $this->canAccessChatbotAdmin(),
                'can_reply' => $this->canAccessChatbotAdmin(),
                'can_takeover' => $this->isChatbotAdmin(),
                'can_manage_status' => $this->isChatbotAdmin(),
            ],
        ];
    }
}
