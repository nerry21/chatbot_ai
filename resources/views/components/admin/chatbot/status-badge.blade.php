@props([
    'value' => null,
    'palette' => null,
    'size' => 'md',
])

@php
    $label = is_string($value) ? trim($value) : (string) $value;
    $normalized = str_replace(' ', '_', strtolower($label));

    $palettes = [
        'slate' => 'border-slate-200 bg-slate-100 text-slate-700',
        'indigo' => 'border-indigo-200 bg-indigo-50 text-indigo-700',
        'blue' => 'border-blue-200 bg-blue-50 text-blue-700',
        'green' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'amber' => 'border-amber-200 bg-amber-50 text-amber-700',
        'orange' => 'border-orange-200 bg-orange-50 text-orange-700',
        'red' => 'border-red-200 bg-red-50 text-red-700',
        'teal' => 'border-teal-200 bg-teal-50 text-teal-700',
        'purple' => 'border-purple-200 bg-purple-50 text-purple-700',
    ];

    $autoPalette = match ($normalized) {
        'active', 'confirmed', 'completed', 'resolved', 'success', 'sent', 'delivered', 'paid', 'enabled', 'bot_active' => 'green',
        'awaiting_confirmation', 'pending', 'assigned', 'warning', 'high' => 'amber',
        'open', 'failed', 'urgent', 'error', 'escalated', 'needs_human' => 'red',
        'takeover', 'admin', 'admin_takeover', 'knowledge_used' => 'orange',
        'bot', 'draft', 'default', 'closed', 'archived', 'skipped', 'disabled' => 'slate',
        'customer' => 'blue',
        'low_confidence', 'fallback' => 'purple',
        default => 'indigo',
    };

    $sizeClasses = match ($size) {
        'sm' => 'px-2 py-0.5 text-[11px]',
        'lg' => 'px-3 py-1 text-sm',
        default => 'px-2.5 py-1 text-xs',
    };

    $colorClass = $palettes[$palette ?? $autoPalette] ?? $palettes['slate'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full border font-medium ' . $sizeClasses . ' ' . $colorClass]) }}>
    {{ $label !== '' ? str_replace('_', ' ', $label) : 'n/a' }}
</span>
