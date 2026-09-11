@php
    // Strings are built here, not inline: Blade only reads `@` as a directive
    // when it does not follow a word character, so `inspection@if(...)` would
    // stay literal text while its `@endif` compiled and broke the view.
    $formName    = $form?->name ?: 'inspection';
    $vehicleName = $vehicle ? ($vehicle->display_name ?? $vehicle->name) : null;
    $forVehicle  = $vehicleName ? ' for ' . $vehicleName : '';
    $senderLine  = $sender?->name ? $sender->name . ' has asked you to complete this inspection.' : 'You have been asked to complete this inspection.';
@endphp
<x-mail-layout>
<h2 style="font-size: 18px; font-weight: 600;">Complete the {{ $formName }} inspection</h2>

@if($recipient && $recipient->name)
<p>Hi {{ $recipient->name }},</p>
@endif

<p>{{ $senderLine }} It is the <strong>{{ $formName }}</strong> inspection{{ $forVehicle }}.</p>

@if($url)
<p style="margin: 24px 0;"><a href="{{ $url }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; font-weight: 600; padding: 10px 18px; border-radius: 6px;">Open the inspection</a></p>
@endif

<p>When it asks, enter this PIN:</p>

<p style="font-size: 28px; font-weight: 700; letter-spacing: 6px; font-family: ui-monospace, Menlo, Consolas, monospace; margin: 16px 0 20px;">{{ $pin }}</p>

<p>The link locks after {{ $maxAttempts }} incorrect PINs.@if($expiresAt) It expires on {{ $expiresAt->format('j M Y, H:i T') }}.@endif</p>

@if($url)
<p style="color: #6b7280; font-size: 13px;">If the button does not work, copy this address into your browser:<br><span style="word-break: break-all;">{{ $url }}</span></p>
@endif

<p style="color: #6b7280; font-size: 13px;">If you were not expecting this, you can ignore it. Do not forward this email: anyone with it can open the inspection.</p>
</x-mail-layout>
