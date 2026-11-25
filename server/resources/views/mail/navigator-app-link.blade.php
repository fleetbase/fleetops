<x-mail-layout>
<h2 style="font-size: 18px; font-weight: 600;">
Connect the driver app
</h2>

Hi {{ $driverName }},
<br />
<br />
{{ $companyName }} uses the Navigator driver app. Open this email on your phone and tap the button to connect the app to {{ $companyName }}.
<br />
<br />
<a href="{{ $linkUrl }}" style="display: inline-block; padding: 12px 20px; border-radius: 8px; background: #2E63D9; color: #ffffff; font-weight: 600; text-decoration: none;">Connect the app</a>
<br />
<br />
If the button does not work, copy this link into your phone's browser:
<br />
{{ $linkUrl }}
<br />
<br />
The link works for 7 days. Install the Navigator app first if you have not already, then sign in with your phone number once it is connected.

</x-mail-layout>
