<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendConversationContactRequest;
use App\Http\Requests\Admin\StoreAdminMobileContactRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Chatbot\AdminConversationContactService;
use App\Services\Chatbot\AdminMobileContactCreateService;
use App\Services\Chatbot\ConversationReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    use RespondsWithAdminMobileJson;

    public function __construct(
        private readonly AdminConversationContactService $contactService,
        private readonly AdminMobileContactCreateService $contactCreateService,
        private readonly ConversationReadService $readService,
    ) {}

    /**
     * GET /api/admin-mobile/contacts
     * Mengembalikan daftar kontak WhatsApp tersimpan untuk admin saat ini.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('admin_mobile_user');
        $userId = $user instanceof User ? (int) $user->id : null;

        $contacts = $this->contactCreateService->listForUser($userId);

        $data = $contacts->map(function (WhatsAppContact $contact): array {
            return [
                'id' => (int) $contact->id,
                'first_name' => (string) $contact->first_name,
                'last_name' => (string) ($contact->last_name ?? ''),
                'display_name' => (string) $contact->display_name,
                'phone_e164' => (string) $contact->phone_e164,
                'email' => $contact->email,
                'avatar_url' => $contact->avatar_url,
                'is_whatsapp_verified' => (bool) $contact->is_whatsapp_verified,
                'sync_to_device' => (bool) $contact->sync_to_device,
                'customer_id' => $contact->customer_id ? (int) $contact->customer_id : null,
                'conversation_id' => $contact->conversation_id ? (int) $contact->conversation_id : null,
                'last_synced_at' => $contact->last_synced_at?->toIso8601String(),
                'created_at' => $contact->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return $this->successResponse('Daftar kontak WhatsApp berhasil dimuat.', [
            'contacts' => $data,
            'total' => count($data),
        ]);
    }

    /**
     * POST /api/admin-mobile/contacts
     * Menyimpan kontak WhatsApp baru + auto-create customer & conversation
     * agar admin bisa langsung mulai chat.
     */
    public function create(StoreAdminMobileContactRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('admin_mobile_user');
        $userId = $user instanceof User ? (int) $user->id : null;

        try {
            $result = $this->contactCreateService->createOrSync([
                'first_name' => (string) $request->input('first_name'),
                'last_name' => (string) $request->input('last_name', ''),
                'full_name' => trim(implode(' ', array_filter([
                    (string) $request->input('first_name'),
                    (string) $request->input('last_name', ''),
                ]))),
                'phone' => (string) $request->input('phone'),
                'email' => $request->input('email'),
                'user_id' => $userId,
                'sync_to_device' => (bool) $request->input('sync_to_device', true),
                'country_code' => (string) $request->input('country_code', ''),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Kontak gagal disimpan ke backend.',
            ], 500);
        }

        /** @var \App\Models\Conversation $conversation */
        $conversation = $result['conversation'];
        /** @var \App\Models\Customer $customer */
        $customer = $result['customer'];
        /** @var \App\Models\WhatsAppContact $whatsappContact */
        $whatsappContact = $result['whatsapp_contact'];

        return $this->successResponse('Kontak berhasil disimpan ke backend Laravel.', [
            'notice' => 'Kontak berhasil disimpan ke backend Laravel.',
            'customer_id' => (int) $customer->id,
            'conversation_id' => (int) $conversation->id,
            'whatsapp_contact_id' => (int) $whatsappContact->id,
            'customer_created' => (bool) ($result['customer_created'] ?? false),
            'conversation_created' => (bool) ($result['conversation_created'] ?? false),
            'whatsapp_contact_created' => (bool) ($result['whatsapp_contact_created'] ?? false),
            'customer' => [
                'id' => (int) $customer->id,
                'name' => (string) ($customer->name ?? ''),
                'phone_e164' => (string) ($customer->phone_e164 ?? ''),
                'email' => $customer->email,
            ],
            'conversation' => [
                'id' => (int) $conversation->id,
                'channel' => (string) $conversation->channel,
                'status' => is_string($conversation->status) ? $conversation->status : $conversation->status?->value,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            ],
            'whatsapp_contact' => [
                'id' => (int) $whatsappContact->id,
                'display_name' => (string) $whatsappContact->display_name,
                'phone_e164' => (string) $whatsappContact->phone_e164,
                'sync_to_device' => (bool) $whatsappContact->sync_to_device,
            ],
        ], 201);
    }

    public function store(
        SendConversationContactRequest $request,
        Conversation $conversation,
    ): JsonResponse {
        /** @var User|null $user */
        $user = $request->attributes->get('admin_mobile_user');

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi admin mobile tidak valid.',
            ], 401);
        }

        $result = $this->contactService->send(
            conversation: $conversation,
            contact: [
                'full_name' => (string) $request->input('full_name'),
                'phone' => (string) $request->input('phone'),
                'email' => $request->input('email'),
                'company' => $request->input('company'),
            ],
            adminId: (int) $user->id,
            source: 'admin_mobile_send_contact',
        );

        $this->readService->markAsRead($conversation, (int) $user->id);

        if (($result['status'] ?? 'failed') === 'failed') {
            return response()->json([
                'success' => false,
                'message' => (string) ($result['error'] ?? 'Kontak gagal dikirim.'),
                'data' => [
                    'conversation_id' => (int) $conversation->id,
                    'transport' => (string) ($result['transport'] ?? $conversation->channel),
                    'duplicate' => false,
                ],
            ], 422);
        }

        $duplicate = (bool) ($result['duplicate'] ?? false);
        $notice = $duplicate
            ? 'Kontak yang sama baru saja dikirim. Duplikat diabaikan.'
            : 'Kontak berhasil diproses untuk dikirim ke customer.';

        return $this->successResponse($notice, [
            'notice' => $notice,
            'conversation_id' => (int) $conversation->id,
            'message_id' => (int) (($result['message']->id ?? 0)),
            'transport' => (string) ($result['transport'] ?? $conversation->channel),
            'duplicate' => $duplicate,
            'delivery_status' => (string) ($result['dispatch_status'] ?? ''),
        ], $duplicate ? 200 : 201);
    }
}