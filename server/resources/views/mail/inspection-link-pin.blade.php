@php
    // Strings are built here, not inline: Blade only reads `@` as a directive
    // when it does not follow a word character, so `inspection@if(...)` would
    // stay literal text while its `@endif` compiled and broke the view.
    $formName    = $form?->name ?: 'inspection';
    $vehicleName = $vehicle ? ($vehicle->display_name ?? $vehicle->name) : null;
    $forVehicle  = $vehicleName ? ' for ' . $vehicleName : '';
    $senderLine  = $sender?->name ? $sender->name . ' will send you the inspection link separately.' : 'You will receive the inspection link separately.';
@endphp
<x-mail-layout>
<h2 style="font-size: 18px; font-weight: 600;">Your inspection PIN</h2>

@if($recipient && $recipient->name)
<p>Hi {{ $recipient->name }},</p>
@endif

<p>Here is the PIN for the <strong>{{ $formName }}</strong> inspection{{ $forVehicle }}.</p>

<p style="font-size: 28px; font-weight: 700; letter-spacing: 6px; font-family: ui-monospace, Menlo, Consolas, monospace; margin: 20px 0;">{{ $pin }}</p>

<p>{{ $senderLine }} The PIN only works with that link, and the link locks after {{ $maxAttempts }} incorrect attempts.</p>

@if($expiresAt)
<p>The link expires on {{ $expiresAt->format('j M Y, H:i T') }}.</p>
@endif

<p style="color: #6b7280; font-size: 13px;">If you were not expecting this, you can ignore it.</p>
</x-mail-layout>
