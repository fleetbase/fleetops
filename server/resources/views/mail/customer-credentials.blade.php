<x-mail-layout>
<h2 style="font-size: 18px; font-weight: 600;">
Your customer portal access is ready
</h2>

Hi {{ $customer->name }},
<br />
<br />
{{ $customer->company->name }} has created customer portal access for you. You can use the portal to view orders, invoices, support requests, and account details when available.
<br />
<br />
<a href="{{ $customerPortalUrl }}" style="display:inline-block; background:#2563eb; color:#ffffff; text-decoration:none; border-radius:6px; padding:12px 18px; font-size:14px; line-height:20px; font-weight:700;">Sign in to customer portal</a>
<br />
<br />
<strong>Your sign-in details</strong>
<br />
Email: {{ $customer->user->email }}
<br />
Temporary password: {{ $plaintextPassword }}
<br />
Portal URL: {{ $customerPortalUrl }}
<br />
<br />
You can change your password after signing in.

</x-mail-layout>
