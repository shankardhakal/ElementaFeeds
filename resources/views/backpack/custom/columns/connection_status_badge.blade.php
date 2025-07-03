@php
    $status = $entry->connection_status;
    $badge_class = match ($status) {
        'connected' => 'bg-success',
        'failed' => 'bg-danger',
        default => 'bg-secondary',
    };
@endphp

<span class="badge {{ $badge_class }}">{{ ucfirst($status) }}</span>
