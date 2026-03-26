<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard the /admin/chatbot/* area.
 *
 * Access rules:
 *   - If config chatbot.security.require_chatbot_admin is false  → any authenticated user passes.
 *   - If user->is_chatbot_admin = true                           → full access.
 *   - If user->is_chatbot_operator = true AND
 *       config chatbot.security.allow_operator_actions = true    → access allowed.
 *   - Otherwise abort 403.
 *
 * NOTE: This middleware expects the 'auth' middleware to run first.
 *       An unauthenticated user will never reach this middleware in the normal route stack.
 */
class EnsureChatbotAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        // If the project admin requirement is disabled, any authenticated user passes.
        if (! config('chatbot.security.require_chatbot_admin', true)) {
            return $next($request);
        }

        $user = $request->user();

        // Extra safety guard — should be handled by 'auth' middleware first.
        if ($user === null) {
            abort(403, 'Unauthenticated.');
        }

        if ($user->canAccessChatbotAdmin()) {
            return $next($request);
        }

        abort(403, 'Akses ditolak. Anda tidak memiliki izin untuk area admin chatbot.');
    }
}
