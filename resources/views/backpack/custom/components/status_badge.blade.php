@props([
    'status',
    'statusMap' => [],
    'defaultClass' => 'bg-secondary',
    'defaultText' => 'Unknown',
])

@php
    $badge_class = $statusMap[$status]['class'] ?? $defaultClass;
    $text = $statusMap[$status]['text'] ?? ucfirst($status) ?? $defaultText;
@endphp

<span class="badge {{ $badge_class }}">{{ $text }}</span>
