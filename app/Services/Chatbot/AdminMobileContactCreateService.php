<?php

namespace App\Services\Chatbot;

use App\Enums\ConversationChannel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\WhatsAppContact;
use App\Services\Support\PhoneNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminMobileContactCreateService
{
    public function __construct(
        private readonly PhoneNumberService $phoneNumberService,
    ) {}

    /**
     * @param array{
     *   first_name:string,
     *   last_name?:string|null,
     *   full_name?:string|null,
     *   phone:string,
     *   email?:string|null,
     *   user_id?:int|null,
     *   sync_to_device?:bool|null,
     *   country_code?:string|null
     * } $payload
     *
     * @return array{
     *   customer: Customer,
     *   conversation: Conversation,
     *   whatsapp_contact: WhatsAppContact,
     *   customer_created: bool,
     *   conversation_created: bool,
     *   whatsapp_contact_created: bool
     * }
     */
    public function createOrSync(array $payload): array
    {
        $firstName = trim((string) ($payload['first_name'] ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? ''));
        $fullName = trim((string) ($payload['full_name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $phoneRaw = trim((string) ($payload['phone'] ?? ''));
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;
        $syncToDevice = (bool) ($payload['sync_to_device'] ?? true);
        $countryCode = trim((string) ($payload['country_code'] ?? ''));

        if ($fullName === '') {
            $fullName = trim(implode(' ', array_filter([$firstName, $lastName])));
        }

        $phoneE164 = $this->phoneNumberService->toE164($phoneRaw);

        if ($fullName === '' || $phoneE164 === '' || ! $this->phoneNumberService->isValidE164($phoneE164)) {
            throw new \InvalidArgumentException('Nama dan nomor telepon wajib valid.');
        }

        return DB::transaction(function () use (
            $firstName,
            $lastName,
            $fullName,
            $email,
            $phoneE164,
            $phoneRaw,
            $userId,
            $syncToDevice,
            $countryCode
        ): array {
            // -----------------------------------------------------------------
            // 1) Customer (data percakapan utama)
            // -----------------------------------------------------------------
            /** @var Customer|null $customer */
            $customer = Customer::query()
                ->where('phone_e164', $phoneE164)
                ->first();

            $customerCreated = false;

            if (! $customer instanceof Customer) {
                $customer = Customer::query()->create([
                    'name' => $fullName,
                    'phone_e164' => $phoneE164,
                    'email' => $email !== '' ? $email : null,
                    'preferred_channel' => ConversationChannel::WhatsApp->value,
                    'last_interaction_at' => now(),
                    'status' => 'active',
                ]);
                $customerCreated = true;
            } else {
                $dirty = false;

                if ($fullName !== '' && (blank($customer->name) || mb_strtolower((string) $customer->name, 'UTF-8') !== mb_strtolower($fullName, 'UTF-8'))) {
                    if (filled($customer->name) && mb_strtolower((string) $customer->name, 'UTF-8') !== mb_strtolower($fullName, 'UTF-8')) {
                        $customer->addAlias((string) $customer->name, 'admin_mobile_previous_name');
                    }

                    $customer->name = $fullName;
                    $dirty = true;
                }

                if ($email !== '' && blank($customer->email)) {
                    $customer->email = $email;
                    $dirty = true;
                }

                $customer->last_interaction_at = now();
                $dirty = true;

                if ($dirty) {
                    $customer->save();
                }
            }

            $customer->addAlias($fullName, 'admin_mobile_contact_create');

            // -----------------------------------------------------------------
            // 2) Conversation (placeholder agar bisa langsung mulai chat)
            // -----------------------------------------------------------------
            /** @var Conversation|null $conversation */
            $conversation = Conversation::query()
                ->where('customer_id', $customer->id)
                ->where('channel', ConversationChannel::WhatsApp->value)
                ->whereNotIn('status', ['closed', 'archived'])
                ->latest('last_message_at')
                ->latest('id')
                ->first();

            $conversationCreated = false;

            if (! $conversation instanceof Conversation) {
                $conversation = Conversation::query()->create([
                    'customer_id' => $customer->id,
                    'channel' => ConversationChannel::WhatsApp->value,
                    'channel_conversation_id' => 'admin-mobile-contact:'.Str::uuid()->toString(),
                    'source_app' => 'admin_mobile',
                    'started_at' => now(),
                    'last_message_at' => now(),
                    'status' => 'active',
                    'summary' => 'Kontak dibuat dari Admin Mobile.',
                    'needs_human' => false,
                    'handoff_mode' => 'bot',
                    'bot_paused' => false,
                    'is_from_mobile_app' => true,
                ]);
                $conversationCreated = true;
            } else {
                $conversation->forceFill([
                    'source_app' => $conversation->source_app ?: 'admin_mobile',
                    'last_message_at' => now(),
                    'status' => (string) $conversation->status === 'archived' ? 'active' : $conversation->status,
                ])->save();
            }

            // -----------------------------------------------------------------
            // 3) WhatsApp Contact (address book)
            //    - Disimpan terpisah dari customer agar admin bisa punya
            //      banyak kontak yang belum tentu pernah chat.
            // -----------------------------------------------------------------
            $contactQuery = WhatsAppContact::query()->where('phone_e164', $phoneE164);
            if ($userId !== null) {
                $contactQuery->where('user_id', $userId);
            } else {
                $contactQuery->whereNull('user_id');
            }

            /** @var WhatsAppContact|null $whatsappContact */
            $whatsappContact = $contactQuery->first();
            $whatsappContactCreated = false;

            if (! $whatsappContact instanceof WhatsAppContact) {
                $whatsappContact = WhatsAppContact::query()->create([
                    'user_id' => $userId,
                    'customer_id' => $customer->id,
                    'conversation_id' => $conversation->id,
                    'first_name' => $firstName !== '' ? $firstName : $fullName,
                    'last_name' => $lastName !== '' ? $lastName : null,
                    'display_name' => $fullName,
                    'phone_e164' => $phoneE164,
                    'phone_raw' => $phoneRaw !== '' ? $phoneRaw : null,
                    'email' => $email !== '' ? $email : null,
                    'country_code' => $countryCode !== '' ? $countryCode : null,
                    'is_whatsapp_verified' => false,
                    'sync_to_device' => $syncToDevice,
                    'source' => 'admin_mobile',
                    'last_synced_at' => now(),
                ]);
                $whatsappContactCreated = true;
            } else {
                $whatsappContact->forceFill([
                    'customer_id' => $customer->id,
                    'conversation_id' => $whatsappContact->conversation_id ?: $conversation->id,
                    'first_name' => $firstName !== '' ? $firstName : $whatsappContact->first_name,
                    'last_name' => $lastName !== '' ? $lastName : $whatsappContact->last_name,
                    'display_name' => $fullName !== '' ? $fullName : $whatsappContact->display_name,
                    'email' => $email !== '' ? $email : $whatsappContact->email,
                    'country_code' => $countryCode !== '' ? $countryCode : $whatsappContact->country_code,
                    'sync_to_device' => $syncToDevice,
                    'last_synced_at' => now(),
                ])->save();
            }

            return [
                'customer' => $customer->fresh(),
                'conversation' => $conversation->fresh(['customer', 'assignedAdmin']),
                'whatsapp_contact' => $whatsappContact->fresh(),
                'customer_created' => $customerCreated,
                'conversation_created' => $conversationCreated,
                'whatsapp_contact_created' => $whatsappContactCreated,
            ];
        });
    }

    /**
     * Daftar kontak WhatsApp milik admin tertentu.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, WhatsAppContact>
     */
    public function listForUser(?int $userId, int $limit = 200): \Illuminate\Database\Eloquent\Collection
    {
        $query = WhatsAppContact::query()
            ->orderBy('display_name')
            ->limit($limit);

        if ($userId !== null) {
            $query->where(function ($q) use ($userId): void {
                $q->where('user_id', $userId)
                    ->orWhereNull('user_id');
            });
        }

        return $query->get();
    }
}