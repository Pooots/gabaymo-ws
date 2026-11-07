<?php

namespace App\Http\Controllers;

use App\Mail\PaymentReceipt;
use App\Models\AuditLog;
use App\Models\PaymongoWebhookEvent;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\UserNotification;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\TokenMeter;
use App\Services\Notifications\NotificationService;
use App\Services\PayMongoCheckout;
use App\Support\Paginates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One-time PayMongo checkouts. Each confirmed payment extends the member's
 * paid period by the plan's billing interval (see SubscriptionService).
 */
class MemberPaymentController extends Controller
{
    use Paginates;

    private const GENERIC_ERROR = 'We could not reach the payment provider. Please try again in a few minutes.';

    public function __construct(private PayMongoCheckout $paymongo) {}

    /** GET /v1/payments: the member's payment history with receipt numbers. */
    public function index(Request $request)
    {
        $query = SubscriptionPayment::query()
            ->with('plan')
            ->where('user_id', $request->user()->id)
            ->latest('id');

        return response()->json($this->paginate($request, $query, fn (SubscriptionPayment $p) => $p->present()));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
        ]);

        $plan = SubscriptionPlan::query()->where('active', true)->findOrFail($data['plan_id']);
        $user = $request->user();

        if ((int) $plan->price <= 0) {
            return response()->json([
                'message' => 'This membership does not require payment.',
            ], 422);
        }

        // Paying for the current plan renews it: the paid period is extended from its end.
        $reference = $this->reference();
        $frontend = rtrim((string) config('services.frontend_url'), '/');

        try {
            $session = $this->paymongo->createSession([
                'billing' => array_filter([
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->mobile,
                ]),
                // GabayMo sends its own receipt (App\Mail\PaymentReceipt).
                'send_email_receipt' => false,
                'show_description' => true,
                'show_line_items' => true,
                'line_items' => [[
                    'currency' => 'PHP',
                    'amount' => (int) $plan->price * 100,
                    'name' => $plan->name,
                    'quantity' => 1,
                    'description' => $plan->allowanceLabel().', '.$plan->cadenceLabel(),
                ]],
                'payment_method_types' => ['card', 'gcash', 'paymaya', 'grab_pay'],
                'success_url' => $frontend.'/account?payment='.$reference,
                'cancel_url' => $frontend.'/account?payment='.$reference.'&cancelled=1',
                'description' => 'GabayMo '.$plan->name,
                'reference_number' => $reference,
                'statement_descriptor' => 'GABAYMO',
                'metadata' => [
                    'reference' => $reference,
                    'plan_id' => (string) $plan->id,
                    'user_id' => (string) $user->id,
                ],
            ]);
        } catch (RuntimeException $exception) {
            Log::error('PayMongo checkout could not be created.', ['user_id' => $user->id, 'plan_id' => $plan->id, 'error' => $exception->getMessage()]);

            return response()->json(['message' => self::GENERIC_ERROR], 502);
        }

        SubscriptionPayment::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'reference' => $reference,
            'paymongo_checkout_id' => $session['id'],
            'paymongo_payment_intent_id' => $session['payment_intent_id'] ?? null,
            'amount' => $plan->price,
            'currency' => 'PHP',
            'status' => 'pending',
        ]);

        return response()->json([
            'reference' => $reference,
            'checkout_url' => $session['checkout_url'],
            'subtotal' => $plan->price,
            'total' => $plan->price,
            'price_label' => $plan->priceLabel(),
        ]);
    }

    public function confirm(Request $request)
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:40'],
        ]);

        $payment = SubscriptionPayment::query()
            ->where('reference', $data['reference'])
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $payment) {
            return response()->json(['message' => 'This checkout was not found.'], 404);
        }

        try {
            $status = $this->settle($payment);
        } catch (RuntimeException $exception) {
            Log::error('PayMongo payment could not be confirmed.', ['reference' => $payment->reference, 'error' => $exception->getMessage()]);

            return response()->json(['message' => self::GENERIC_ERROR], 502);
        }

        return response()->json([
            'status' => $status,
            'reference' => $payment->reference,
            'receipt_number' => $payment->fresh()->receipt_number,
        ]);
    }

    /**
     * PayMongo webhooks. Signed (Paymongo-Signature) and idempotent per event
     * id; state checks make a replayed or out-of-order event harmless too.
     */
    public function webhook(Request $request)
    {
        $payload = $request->getContent();
        if (! $this->paymongo->signatureValid($payload, $request->header('Paymongo-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $eventId = $request->json('data.id');
        $event = (string) $request->json('data.attributes.type');
        $resource = (array) ($request->json('data.attributes.data') ?? []);
        if (! is_string($eventId) || $eventId === '' || $event === '') {
            return response()->json(['ok' => true]);
        }

        if (PaymongoWebhookEvent::query()->where('event_id', $eventId)->exists()) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            [$outcome, $payment] = match (true) {
                $event === 'checkout_session.payment.paid' => $this->onPaid(SubscriptionPayment::query()->where('paymongo_checkout_id', $resource['id'] ?? '')->first()),
                $event === 'payment.paid' => $this->onPaid($this->findForPayment($resource)),
                $event === 'payment.failed' => $this->onFailed($this->findForPayment($resource), $resource),
                $event === 'payment.refunded', str_starts_with($event, 'payment.refund'), str_starts_with($event, 'refund.') => $this->onRefund($resource, $event),
                str_contains($event, 'dispute') || str_contains($event, 'chargeback') => $this->onDispute($this->findForPayment($resource), $event),
                default => ['ignored', null],
            };
        } catch (RuntimeException $exception) {
            // Not recorded, so PayMongo's retry processes it again.
            Log::error('PayMongo webhook could not be processed.', ['event' => $event, 'event_id' => $eventId, 'error' => $exception->getMessage()]);

            return response()->json(['message' => 'The payment could not be confirmed yet.'], 502);
        }

        PaymongoWebhookEvent::query()->firstOrCreate(['event_id' => $eventId], [
            'type' => mb_substr($event, 0, 80),
            'resource_id' => is_string($resource['id'] ?? null) ? $resource['id'] : null,
            'subscription_payment_id' => $payment?->id,
            'outcome' => $outcome,
        ]);

        return response()->json(['ok' => true]);
    }

    /** @return array{0: string, 1: ?SubscriptionPayment} */
    private function onPaid(?SubscriptionPayment $payment): array
    {
        if (! $payment) {
            return ['unknown_payment', null];
        }

        return [$this->settle($payment), $payment];
    }

    /** @return array{0: string, 1: ?SubscriptionPayment} */
    private function onFailed(?SubscriptionPayment $payment, array $resource): array
    {
        if (! $payment) {
            return ['unknown_payment', null];
        }
        $updated = SubscriptionPayment::query()->whereKey($payment->id)->where('status', 'pending')->update([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => mb_substr((string) (data_get($resource, 'attributes.last_payment_error.message')
                ?? data_get($resource, 'attributes.last_payment_error.code')
                ?? 'payment_failed'), 0, 200),
            'paymongo_payment_id' => $payment->paymongo_payment_id ?? (is_string($resource['id'] ?? null) ? $resource['id'] : null),
        ]);
        if ($updated) {
            AuditLog::record('payment.failed', 'Payment '.$payment->reference.' failed', $payment->user);
        }

        return [$updated ? 'failed' : 'unchanged', $payment];
    }

    /** @return array{0: string, 1: ?SubscriptionPayment} */
    private function onRefund(array $resource, string $event): array
    {
        $type = $resource['type'] ?? null;
        $attributes = (array) ($resource['attributes'] ?? []);

        if ($type === 'refund' || str_starts_with((string) ($resource['id'] ?? ''), 'ref_')) {
            if (($attributes['status'] ?? 'succeeded') !== 'succeeded') {
                return ['refund_'.($attributes['status'] ?? 'pending'), null];
            }
            $payment = $this->findByPaymentId($attributes['payment_id'] ?? null);
            if (! $payment) {
                return ['unknown_payment', null];
            }
            $refundId = (string) ($resource['id'] ?? '');
            if ($refundId !== '' && PaymongoWebhookEvent::query()->where('resource_id', $refundId)->where('outcome', 'like', 'refund%')->exists()) {
                return ['refund_seen', $payment];
            }
            $centavos = (int) $payment->refunded_amount * 100 + (int) ($attributes['amount'] ?? 0);
        } else {
            $payment = $this->findForPayment($resource);
            if (! $payment) {
                return ['unknown_payment', null];
            }
            $centavos = 0;
            foreach ((array) ($attributes['refunds'] ?? []) as $refund) {
                if ((data_get($refund, 'attributes.status') ?? 'succeeded') === 'succeeded') {
                    $centavos += (int) data_get($refund, 'attributes.amount', 0);
                }
            }
            if ($centavos === 0 && ($attributes['status'] ?? null) === 'refunded') {
                $centavos = (int) ($attributes['amount'] ?? $payment->amount * 100);
            }
        }

        return [$this->applyRefund($payment, $centavos, $event), $payment];
    }

    private function applyRefund(SubscriptionPayment $payment, int $refundedCentavos, string $event): string
    {
        $outcome = DB::transaction(function () use ($payment, $refundedCentavos) {
            $locked = SubscriptionPayment::query()->whereKey($payment->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'refunded') {
                return 'unchanged';
            }
            $refunded = min((int) $locked->amount, intdiv(max($refundedCentavos, (int) $locked->refunded_amount * 100), 100));
            $full = $refunded >= (int) $locked->amount;
            $wasPaid = in_array($locked->status, ['paid', 'partially_refunded', 'disputed'], true);
            $locked->forceFill([
                'refunded_amount' => $refunded,
                'status' => $full ? 'refunded' : 'partially_refunded',
                'refunded_at' => $full ? now() : $locked->refunded_at,
            ])->save();

            return $full ? ($wasPaid ? 'refunded_revoke' : 'refunded') : 'refund_partial';
        });

        $payment->refresh();
        if ($outcome === 'refunded_revoke') {
            // The period this payment bought is taken back.
            app(SubscriptionService::class)->revokePayment($payment);
        }
        if ($outcome !== 'unchanged') {
            Log::warning('PayMongo refund recorded.', ['reference' => $payment->reference, 'event' => $event, 'refunded' => $payment->refunded_amount]);
            AuditLog::record('payment.refund', 'Refund of ₱'.number_format($payment->refunded_amount).' on '.$payment->reference.($outcome === 'refund_partial' ? ' (partial)' : ''), $payment->user);
        }

        return $outcome;
    }

    /** @return array{0: string, 1: ?SubscriptionPayment} */
    private function onDispute(?SubscriptionPayment $payment, string $event): array
    {
        if (! $payment) {
            return ['unknown_payment', null];
        }
        $payment->forceFill(['status' => 'disputed', 'disputed_at' => $payment->disputed_at ?? now()])->save();
        Log::warning('PayMongo dispute received.', ['reference' => $payment->reference, 'event' => $event]);
        AuditLog::record('payment.dispute', 'Payment '.$payment->reference.' is disputed ('.$event.')', $payment->user);

        return ['disputed', $payment];
    }

    private function findForPayment(array $resource): ?SubscriptionPayment
    {
        $attributes = (array) ($resource['attributes'] ?? []);

        return $this->findByPaymentId($resource['id'] ?? null)
            ?? $this->findByPaymentId($attributes['payment_id'] ?? null)
            ?? $this->findBy('paymongo_payment_intent_id', $attributes['payment_intent_id'] ?? null)
            ?? $this->findBy('reference', data_get($attributes, 'metadata.reference'));
    }

    private function findByPaymentId(mixed $id): ?SubscriptionPayment
    {
        return $this->findBy('paymongo_payment_id', $id);
    }

    private function findBy(string $column, mixed $value): ?SubscriptionPayment
    {
        return is_string($value) && $value !== '' ? SubscriptionPayment::query()->where($column, $value)->first() : null;
    }

    private function settle(SubscriptionPayment $payment): string
    {
        $payment->refresh();
        if ($payment->status !== 'pending' || ! $payment->paymongo_checkout_id) {
            return $payment->status;
        }

        $remote = $this->paymongo->status($payment->paymongo_checkout_id);
        if ($remote['status'] !== 'succeeded') {
            if ($remote['status'] === 'failed' && $payment->status === 'pending') {
                $payment->forceFill(['status' => 'failed', 'failed_at' => now()])->save();
            }

            return $payment->fresh()->status;
        }

        if ($remote['amount_centavos'] !== (int) $payment->amount * 100) {
            if ($payment->failure_reason !== 'amount_mismatch') {
                Log::warning('PayMongo amount does not match the checkout.', [
                    'reference' => $payment->reference,
                    'expected_centavos' => (int) $payment->amount * 100,
                    'paid_centavos' => $remote['amount_centavos'],
                ]);
                AuditLog::record('payment.amount_mismatch', 'Payment '.$payment->reference.' was not applied: PayMongo reported '.number_format($remote['amount_centavos'] / 100, 2).' instead of '.number_format((int) $payment->amount, 2), $payment->user);
                $payment->forceFill(['failure_reason' => 'amount_mismatch'])->save();
            }

            return $payment->status;
        }

        $applied = DB::transaction(function () use ($payment, $remote) {
            $locked = SubscriptionPayment::query()->whereKey($payment->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'pending') {
                return false;
            }

            $plan = $locked->plan;
            $user = $locked->user;
            if (! $plan || ! $user) {
                return false;
            }

            $member = app(TokenMeter::class)->lock($user->id);
            $period = app(SubscriptionService::class)->applyPayment($member, $plan);

            $locked->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
                'paymongo_payment_id' => $remote['payment_id'] ?? $locked->paymongo_payment_id,
                'paymongo_payment_intent_id' => $remote['payment_intent_id'] ?? $locked->paymongo_payment_intent_id,
                'period_end_before' => $period['before'],
                'period_end_after' => $period['after'],
                'changed_plan' => $period['changed_plan'],
                'failure_reason' => null,
            ])->save();
            $locked->forceFill(['receipt_number' => $this->receiptNumber($locked)])->save();

            AuditLog::record('subscription.pay', $user->name.' paid for '.$plan->name.' (receipt '.$locked->receipt_number.')', $user);

            return true;
        });

        $payment->refresh();
        if ($applied) {
            $this->sendReceipt($payment);
        }

        return $payment->status;
    }

    private function sendReceipt(SubscriptionPayment $payment): void
    {
        $user = $payment->user;
        if (! $user) {
            return;
        }
        try {
            Mail::to($user->email)->queue(new PaymentReceipt($payment));
            $payment->forceFill(['receipt_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            report($e);
        }
        app(NotificationService::class)->send(
            $user,
            UserNotification::PAYMENT_RECEIPT,
            'Payment received — receipt '.$payment->receipt_number,
            ($payment->plan?->name ?? 'Membership').' active until '.$payment->period_end_after?->timezone(config('app.timezone'))->format('F j, Y').'.',
            ['payment_id' => $payment->id, 'receipt_number' => $payment->receipt_number],
        );
    }

    /** Sequential, human-readable receipt number: GM-OR-YYYYMM-000123. */
    private function receiptNumber(SubscriptionPayment $payment): string
    {
        return 'GM-OR-'.now()->format('Ym').'-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT);
    }

    private function reference(): string
    {
        do {
            $reference = 'GM'.strtoupper(Str::random(10));
        } while (SubscriptionPayment::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
