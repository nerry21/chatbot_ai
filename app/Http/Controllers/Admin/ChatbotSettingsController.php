<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ChatbotSettingsController extends Controller
{
    public function index(): View
    {
        $settings = [
            'whatsapp_enabled' => (bool) config('chatbot.whatsapp.enabled', false),
            'llm_enabled' => (bool) config('chatbot.llm.enabled', true),
            'knowledge_enabled' => (bool) config('chatbot.knowledge.enabled', true),
            'crm_enabled' => (bool) config('chatbot.crm.enabled', false),
            'require_chatbot_admin' => (bool) config('chatbot.security.require_chatbot_admin', true),
            'allow_operator_actions' => (bool) config('chatbot.security.allow_operator_actions', false),
            'repair_corrupted_state' => (bool) config('chatbot.guards.repair_corrupted_state', true),
            'notifications_enabled' => (bool) config('chatbot.notifications.enabled', true),
        ];

        return view('admin.chatbot.settings.index', compact('settings'));
    }
}
