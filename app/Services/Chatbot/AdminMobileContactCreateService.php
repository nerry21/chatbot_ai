<?php

namespace App\Services\Chatbot;

use App\Enums\ConversationChannel;
use App\Models\Conversation;
use App\Models\Customer;
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
     *   email?:string|null
     * } $payload
     *
     * @return array{
     *   customer: Customer,
     *   conversation: Conversation,
     *   customer_created: bool,
     *   conversation_created: bool
     * }
     */
    public function createOrSync(array $payload): array
    {
        $firstName = trim((string) ($payload['first_name'] ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? ''));
        $fullName = trim((string) ($payload['full_name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $phoneRaw = trim((string) ($payload['phone'] ?? ''));

        if ($fullName === '') {
            $fullName = trim(implode(' ', array_filter([$firstName, $lastName])));
        }

        $phoneE164 = $this->phoneNumberService->toE164($phoneRaw);

        if ($fullName === '' || $phoneE164 === '' || ! $this->phoneNumberService->isValidE164($phoneE164)) {
            throw new \InvalidArgumentException('Nama dan nomor telepon wajib valid.');
        }

        return DB::transaction(function () use ($fullName, $email, $phoneE164): array {
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

            return [
                'customer' => $customer->fresh(),
                'conversation' => $conversation->fresh(['customer', 'assignedAdmin']),
                'customer_created' => $customerCreated,
                'conversation_created' => $conversationCreated,
            ];
        });
    }
}
