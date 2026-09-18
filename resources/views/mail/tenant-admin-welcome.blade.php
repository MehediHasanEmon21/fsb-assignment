<x-mail::message>
# Welcome, {{ $adminName }}

Your tenant **{{ $tenantName }}** is ready.

Use these temporary credentials to sign in:

- Email: {{ $adminEmail }}
- Temporary password: {{ $temporaryPassword }}

Change your password after your first sign-in.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
