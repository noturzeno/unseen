@props(['status'])
@php
    $tone = match ($status) {
        \App\Enums\FeatureStatus::Implemented, \App\Enums\VerificationStatus::Verified => 'ok',
        \App\Enums\FeatureStatus::InProgress, \App\Enums\VerificationStatus::NeedsVerification => 'info',
        \App\Enums\FeatureStatus::Blocked => 'warn',
        \App\Enums\VerificationStatus::Failed => 'bad',
        default => '',
    };
@endphp
<span {{ $attributes->class(['badge', $tone]) }}>{{ $status->label() }}</span>
