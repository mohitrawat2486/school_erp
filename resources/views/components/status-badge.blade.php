@props(['value','label'=>null,'muted'=>false])
@php
    $tone = $muted ? 'neutral' : match(strtoupper($value)) {
        'ACTIVE','VERIFIED','PUBLISHED' => 'success',
        'DRAFT','PENDING' => 'warning',
        'DISABLED','INACTIVE' => 'neutral',
        'CLOSED','ARCHIVED','WITHDRAWN','TRANSFERRED' => 'neutral',
        default => 'info',
    };
@endphp
<span class="status-badge status-badge--{{ $tone }}"><span class="status-badge-dot" aria-hidden="true"></span>{{ $label ?? $value }}</span>
