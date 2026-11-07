<x-mail::message>
@if ($reset)
# Set a new password

Hello {{ $name }}, {{ $invitedBy }} sent you a link to set a new password for your {{ config('app.name') }} account ({{ $role }}).
@else
# Welcome to {{ config('app.name') }}

Hello {{ $name }}, {{ $invitedBy }} invited you to {{ config('app.name') }} as **{{ $role }}**.

Choose a password and finish your profile to start.
@endif

<x-mail::button :url="$url">
Set up your account
</x-mail::button>

This link works once and expires on {{ $expires }}. If it has expired, ask your administrator to send a new one.

If you were not expecting this email, you can ignore it.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
