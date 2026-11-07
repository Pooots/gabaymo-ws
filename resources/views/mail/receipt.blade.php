<x-mail::message>
# Thank you for your payment

Hello {{ $name }}, this is your official GabayMo receipt.

<x-mail::table>
| | |
|:--|--:|
| Receipt number | {{ $payment->receipt_number }} |
| Reference | {{ $payment->reference }} |
| Membership | {{ $plan }} |
| Amount paid | {{ $amount }} {{ $payment->currency }} |
| Paid on | {{ $paidAt }} |
@if ($periodEnd)
| Membership active until | {{ $periodEnd }} |
@endif
</x-mail::table>

Payments are one-time: we will remind you before your membership ends.

<x-mail::button :url="$url">
View my account
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
