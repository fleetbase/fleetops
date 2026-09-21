<x-mail-layout>
<h2 style="font-size: 18px; font-weight: 600;">
Your driver sign-in details
</h2>

Hi {{ $user->name }},
<br />
<br />
{{ $companyName }} has set up your driver account. Use these details to sign in to the driver app.
<br />
<br />
<strong>Your sign-in details</strong>
<br />
Login: {{ $identity }}
<br />
Temporary password: {{ $plaintextPassword }}
<br />
<br />
You can change your password after signing in.

</x-mail-layout>
