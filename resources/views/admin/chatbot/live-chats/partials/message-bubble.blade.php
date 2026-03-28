@php
    $direction = is_string($message->direction) ? $message->direction : $message->direction?->value;
    $senderType = is_string($message->sender_type) ? $message->sender_type : $message->sender_type?->value;
    $deliveryStatus = is_string($message->delivery_status) ? $message->delivery_status : $message->delivery_status?->value;
    $interactiveSelection = data_get($message->raw_payload, '_interactive_selection');
    $interactivePayload = data_get($message->raw_payload, 'outbound_payload.interactive');
    $isSystem = $senderType === 'system';
    $alignment = $senderType === 'customer' || $direction === 'inbound' ? 'justify-start' : 'justify-end';
    $bubbleClass = match ($senderType) {
        'customer' => 'bg-white text-slate-800 ring-slate-200',
        'admin' => 'bg-teal-600 text-white ring-teal-500/20',
        'agent' => 'bg-teal-600 text-white ring-teal-500/20',
        'system' => 'bg-amber-50 text-amber-800 ring-amber-200',
        default => 'bg-slate-900 text-white ring-slate-800/15',
    };
    $label = match ($senderType) {
        'customer' => ['Customer', 'blue'],
        'admin' => ['Admin', 'teal'],
        'agent' => ['Admin', 'teal'],
        'system' => ['System', 'amber'],
        default => ['Bot', 'indigo'],
    };
    $adminName = trim((string) data_get($message->raw_payload, 'admin_name', ''));
    $deliveryLabel = match ($deliveryStatus) {
        'pending' => 'sending',
        'sent', 'delivered' => 'sent',
        'failed' => 'failed',
        'skipped' => 'skipped',
        default => $deliveryStatus,
    };
    $deliveryPalette = match ($deliveryStatus) {
        'pending' => 'amber',
        'sent', 'delivered' => 'green',
        'failed' => 'red',
        'skipped' => 'slate',
        default => null,
    };
    $interactiveType = is_array($interactivePayload) ? (string) ($interactivePayload['type'] ?? '') : '';
    $interactiveButtons = collect(data_get($interactivePayload, 'action.buttons', []))
        ->map(fn (array $button): ?string => data_get($button, 'reply.title'))
        ->filter()
        ->values();
    $interactiveRows = collect(data_get($interactivePayload, 'action.sections', []))
        ->flatMap(fn (array $section): array => $section['rows'] ?? [])
        ->map(fn (array $row): ?string => $row['title'] ?? null)
        ->filter()
        ->take(6)
        ->values();
    $isBookingReview = filled(data_get($message->raw_payload, 'review_hash'));
@endphp

@if ($isSystem)
    <div class="flex justify-center">
        <div class="max-w-md rounded-full border border-amber-200 bg-amber-50 px-4 py-2 text-xs font-medium text-amber-700 shadow-sm">
            {{ $message->message_text ?? '[system event]' }}
        </div>
    </div>
@else
    <div class="flex {{ $alignment }}">
        <div class="max-w-[82%]">
            <div class="rounded-[26px] px-4 py-3 shadow-sm ring-1 {{ $bubbleClass }}">
                <div class="text-sm leading-7">
                    {{ $message->message_text ?? '[non-text]' }}
                </div>

                @if ($isBookingReview || $interactiveSelection || $interactiveType !== '')
                    <div class="mt-4 rounded-[20px] border border-white/10 bg-black/10 px-3 py-3 text-xs leading-6 {{ in_array($senderType, ['bot', 'agent', 'admin'], true) ? 'text-white/85' : 'bg-slate-50 text-slate-600' }}">
                        @if ($isBookingReview)
                            <div class="mb-2 inline-flex items-center rounded-full bg-white/15 px-2.5 py-1 font-semibold {{ in_array($senderType, ['bot', 'agent', 'admin'], true) ? 'text-white' : 'bg-slate-100 text-slate-700' }}">
                                Booking review
                            </div>
                        @endif

                        @if (is_array($interactiveSelection))
                            <div>
                                <div class="font-semibold">Pilihan user</div>
                                <div class="mt-1">{{ $interactiveSelection['title'] ?? $interactiveSelection['id'] ?? '-' }}</div>
                            </div>
                        @endif

                        @if ($interactiveType === 'button' && $interactiveButtons->isNotEmpty())
                            <div class="{{ is_array($interactiveSelection) ? 'mt-3' : '' }}">
                                <div class="font-semibold">Button options</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($interactiveButtons as $buttonTitle)
                                        <span class="rounded-full border px-2.5 py-1 {{ in_array($senderType, ['bot', 'agent', 'admin'], true) ? 'border-white/20 bg-white/10' : 'border-slate-200 bg-white' }}">
                                            {{ $buttonTitle }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if ($interactiveType === 'list' && $interactiveRows->isNotEmpty())
                            <div class="{{ is_array($interactiveSelection) ? 'mt-3' : '' }}">
                                <div class="font-semibold">List options</div>
                                <div class="mt-2 grid gap-2">
                                    @foreach ($interactiveRows as $rowTitle)
                                        <div class="rounded-2xl border px-3 py-2 {{ in_array($senderType, ['bot', 'agent', 'admin'], true) ? 'border-white/10 bg-white/10' : 'border-slate-200 bg-white' }}">
                                            {{ $rowTitle }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs {{ $alignment === 'justify-end' ? 'justify-end' : '' }}">
                <x-admin.chatbot.status-badge :value="$label[0]" :palette="$label[1]" size="sm" />
                @if ($senderType === 'admin' && $adminName !== '')
                    <x-admin.chatbot.status-badge :value="$adminName" palette="teal" size="sm" />
                @endif
                @if ($deliveryStatus && $direction === 'outbound')
                    <x-admin.chatbot.status-badge :value="$deliveryLabel" :palette="$deliveryPalette" size="sm" />
                @endif
                @if ($message->ai_intent)
                    <x-admin.chatbot.status-badge :value="$message->ai_intent" palette="purple" size="sm" />
                @endif
                <span class="text-slate-400">{{ $message->sent_at?->format('H:i') ?? '-' }}</span>
            </div>

            @if ($deliveryStatus === 'failed' && $message->delivery_error)
                <div class="mt-2 text-right text-xs text-red-500">
                    {{ \Illuminate\Support\Str::limit($message->delivery_error, 140) }}
                </div>
            @endif
        </div>
    </div>
@endif
