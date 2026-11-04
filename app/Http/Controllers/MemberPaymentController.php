<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Services\PayMongoCheckout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MemberPaymentController extends Controller
{
    public function __construct(private PayMongoCheckout $paymongo) {}

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

        if ((int) $user->subscription_plan_id === (int) $plan->id) {
            return response()->json([
                'message' => 'This is already your membership.',
            ], 422);
        }

        $reference = $this->reference();
        $frontend = rtrim((string) config('services.frontend_url'), '/');

        try {
            $session = $this->paymongo->createSession([
                'billing' => array_filter([
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->mobile,
                ]),
                'send_email_receipt' => false,
                'show_description' => true,
                'show_line_items' => true,
                'line_items' => [[
                    'currency' => 'PHP',
                    'amount' => (int) $plan->price * 100,
                    'name' => $plan->name,
                    'quantity' => 1,
                    'description' => number_format($plan->tokens).' questions, '.$plan->cadenceLabel(),
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
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        SubscriptionPayment::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'reference' => $reference,
            'paymongo_checkout_id' => $session['id'],
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
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json([
            'status' => $status,
            'reference' => $payment->reference,
        ]);
    }

    public function webhook(Request $request)
    {
        $payload = $request->getContent();
        if (! $this->paymongo->signatureValid($payload, $request->header('Paymongo-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = $request->json('data.attributes.type');
        $checkoutId = $request->json('data.attributes.data.id');
        if ($event !== 'checkout_session.payment.paid' || ! is_string($checkoutId)) {
            return response()->json(['ok' => true]);
        }

        $payment = SubscriptionPayment::query()
            ->where('paymongo_checkout_id', $checkoutId)
            ->first();

        if ($payment) {
            try {
                $this->settle($payment);
            } catch (RuntimeException) {
                return response()->json(['message' => 'PayMongo could not confirm the payment.'], 502);
            }
        }

        return response()->json(['ok' => true]);
    }

    private function settle(SubscriptionPayment $payment): string
    {
        $payment->refresh();
        if ($payment->status === 'paid' || ! $payment->paymongo_checkout_id) {
            return $payment->status;
        }

        $remote = $this->paymongo->status($payment->paymongo_checkout_id);
        if ($remote['status'] !== 'succeeded') {
            if ($remote['status'] === 'failed' && $payment->status === 'pending') {
                $payment->forceFill(['status' => 'failed'])->save();
            }

            return $payment->fresh()->status;
        }

        if ($remote['amount_centavos'] !== (int) $payment->amount * 100) {
            return $payment->status;
        }

        DB::transaction(function () use ($payment) {
            $locked = SubscriptionPayment::query()->whereKey($payment->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'paid') {
                return;
            }

            $plan = $locked->plan;
            $user = $locked->user;
            if (! $plan || ! $user) {
                return;
            }

            $locked->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
            ])->save();

            $user->forceFill([
                'subscription_plan_id' => $plan->id,
                'tokens_remaining' => $plan->tokens,
            ])->save();

            AuditLog::query()->create([
                'user_id' => $user->id,
                'action' => 'subscription.pay',
                'summary' => $user->name.' paid for '.$plan->name,
            ]);
        });

        return $payment->fresh()->status;
    }

    private function reference(): string
    {
        do {
            $reference = 'GM'.strtoupper(Str::random(10));
        } while (SubscriptionPayment::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
