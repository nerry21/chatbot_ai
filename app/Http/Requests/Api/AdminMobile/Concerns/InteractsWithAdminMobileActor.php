<?php

namespace App\Http\Requests\Api\AdminMobile\Concerns;

use App\Models\User;

trait InteractsWithAdminMobileActor
{
    protected function adminMobileUser(): ?User
    {
        $user = $this->attributes->get('admin_mobile_user');

        return $user instanceof User ? $user : null;
    }

    protected function canAccessConversationActions(): bool
    {
        return $this->adminMobileUser()?->canAccessChatbotAdmin() ?? false;
    }

    protected function isFullAdmin(): bool
    {
        return $this->adminMobileUser()?->isChatbotAdmin() ?? false;
    }
}
