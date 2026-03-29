<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_chatbot_admin',
        'is_chatbot_operator',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'   => 'datetime',
            'password'            => 'hashed',
            'is_chatbot_admin'    => 'boolean',
            'is_chatbot_operator' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Role helpers — used by EnsureChatbotAdminAccess middleware
    // -------------------------------------------------------------------------

    /**
     * Returns true if this user has full admin access to the chatbot dashboard.
     * Admins can takeover conversations, manage escalations, and access all areas.
     */
    public function isChatbotAdmin(): bool
    {
        return (bool) $this->is_chatbot_admin;
    }

    /**
     * Returns true if this user is a chatbot operator.
     * Operators have limited access (read + reply) when config allow_operator_actions = true.
     */
    public function isChatbotOperator(): bool
    {
        return (bool) $this->is_chatbot_operator;
    }

    /**
     * Returns true if this user can access the chatbot admin area.
     * Used by EnsureChatbotAdminAccess middleware.
     */
    public function canAccessChatbotAdmin(): bool
    {
        if ($this->isChatbotAdmin()) {
            return true;
        }

        if ($this->isChatbotOperator() && config('chatbot.security.allow_operator_actions', true)) {
            return true;
        }

        return false;
    }

    public function adminMobileAccessTokens(): HasMany
    {
        return $this->hasMany(AdminMobileAccessToken::class);
    }
}
