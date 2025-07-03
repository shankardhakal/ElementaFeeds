@php
    $status = $entry->connection_status ?? 'untested';
    $lastChecked = $entry->last_checked_at ? $entry->last_checked_at->diffForHumans() : 'Never';

    $badge_class = match ($status) {
        'ok' => 'bg-success',
        'failed' => 'bg-danger',
        default => 'bg-secondary',
    };
    $text = match ($status) {
        'ok' => 'Connected',
        'failed' => 'Failed',
        default => 'Untested',
    };
@endphp

<div>
    <span class="badge {{ $badge_class }}">{{ $text }}</span>
    <small class="d-block text-muted mt-1">Checked: {{ $lastChecked }}</small>
</div>
