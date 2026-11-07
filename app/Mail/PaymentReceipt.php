<?php

namespace App\Mail;

use App\Models\SubscriptionPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** GabayMo's own receipt (PayMongo's send_email_receipt stays off). */
class PaymentReceipt extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SubscriptionPayment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'GabayMo receipt '.$this->payment->receipt_number);
    }

    public function content(): Content
    {
        $payment = $this->payment->loadMissing('plan', 'user');

        return new Content(markdown: 'mail.receipt', with: [
            'payment' => $payment,
            'name' => $payment->user?->name,
            'plan' => $payment->plan?->name,
            'amount' => '₱'.number_format((int) $payment->amount, 2),
            'paidAt' => $payment->paid_at?->timezone(config('app.timezone'))->format('F j, Y g:i A'),
            'periodEnd' => $payment->period_end_after?->timezone(config('app.timezone'))->format('F j, Y'),
            'url' => rtrim((string) config('services.frontend_url'), '/').'/account',
        ]);
    }
}
