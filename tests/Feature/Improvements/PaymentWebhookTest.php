<?php

namespace Tests\Feature\Improvements;

use App\Mail\PaymentReceipt;
use App\Models\AuditLog;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/** PayMongo webhook: signature, paid/failed/refund/mismatch, idempotency, receipts. */
class PaymentWebhookTest extends ImprovementTestCase
{
    private const SECRET = 'whsk_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.paymongo.webhook_secret' => self::SECRET,
            'services.paymongo.secret_key' => 'sk_test_x',
            'services.paymongo.env' => 'sandbox',
        ]);
    }

    private function remote(int $centavos = 49900, string $intent = 'succeeded'): void
    {
        Http::fake(['api.paymongo.com/v1/checkout_sessions/*' => Http::response(['data' => ['id' => 'cs_1', 'attributes' => [
            'status' => 'active',
            'payment_intent' => ['id' => 'pi_1', 'attributes' => ['status' => $intent]],
            'payments' => $intent === 'succeeded' ? [['id' => 'pay_1', 'attributes' => ['status' => 'paid']]] : [],
            'line_items' => [['amount' => $centavos, 'quantity' => 1]],
        ]]])]);
    }

    /** @return array{0: User, 1: SubscriptionPayment, 2: SubscriptionPlan} */
    private function pending(): array
    {
        $plan = $this->paidPlan();
        $user = $this->member();
        $payment = SubscriptionPayment::query()->create([
            'user_id' => $user->id, 'subscription_plan_id' => $plan->id, 'reference' => 'GMREF'.uniqid(),
            'paymongo_checkout_id' => 'cs_1', 'paymongo_payment_intent_id' => 'pi_1', 'amount' => 499, 'currency' => 'PHP', 'status' => 'pending',
        ]);

        return [$user, $payment, $plan];
    }

    private function hook(string $type, array $resource, ?string $eventId = null, ?string $secret = null): TestResponse
    {
        $body = json_encode(['data' => ['id' => $eventId ?? 'evt_'.uniqid(), 'type' => 'event', 'attributes' => ['type' => $type, 'data' => $resource]]]);
        $t = (string) time();
        $sig = hash_hmac('sha256', $t.'.'.$body, $secret ?? self::SECRET);

        return $this->call('POST', '/api/v1/paymongo/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => "t={$t},te={$sig},li=",
        ], $body);
    }

    public function test_bad_signature_is_rejected(): void
    {
        [, $payment] = $this->pending();
        $this->hook('checkout_session.payment.paid', ['id' => 'cs_1'], null, 'wrong')->assertStatus(401);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_paid_event_extends_the_period_sends_a_receipt_and_is_idempotent(): void
    {
        Mail::fake();
        $this->remote();
        [$user, $payment, $plan] = $this->pending();

        $this->hook('checkout_session.payment.paid', ['id' => 'cs_1'], 'evt_paid_1')->assertOk();
        $this->hook('checkout_session.payment.paid', ['id' => 'cs_1'], 'evt_paid_1')->assertOk()->assertJsonPath('duplicate', true);
        $this->hook('checkout_session.payment.paid', ['id' => 'cs_1'], 'evt_paid_2')->assertOk();

        $payment->refresh();
        $user->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('pay_1', $payment->paymongo_payment_id);
        $this->assertMatchesRegularExpression('/^GM-OR-\d{6}-\d{6}$/', $payment->receipt_number);
        $this->assertSame($plan->id, $user->subscription_plan_id);
        $this->assertSame('active', $user->subscription_status);
        $this->assertTrue($user->current_period_end->isSameDay(now()->addMonthNoOverflow()));
        Mail::assertQueued(PaymentReceipt::class, 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.pay')->count());
        $this->assertDatabaseCount('paymongo_webhook_events', 2);

        $this->actingAs($user, 'web')->getJson('/api/v1/payments')
            ->assertOk()->assertJsonPath('data.0.receipt_number', $payment->receipt_number)->assertJsonPath('meta.total', 1);
    }

    public function test_paying_again_for_the_same_plan_extends_from_the_current_end(): void
    {
        Mail::fake();
        $this->remote();
        [$user, $payment, $plan] = $this->pending();
        $end = now()->addDays(10);
        $user->forceFill(['subscription_plan_id' => $plan->id, 'subscription_status' => 'active', 'plan_source' => 'personal', 'current_period_end' => $end])->save();

        $this->hook('checkout_session.payment.paid', ['id' => 'cs_1'])->assertOk();

        $this->assertTrue($user->fresh()->current_period_end->isSameDay($end->copy()->addMonthNoOverflow()));
        $this->assertFalse($payment->fresh()->changed_plan);
    }

    public function test_failed_payment_is_marked(): void
    {
        [, $payment] = $this->pending();
        $this->hook('payment.failed', ['id' => 'pay_9', 'type' => 'payment', 'attributes' => ['payment_intent_id' => 'pi_1', 'status' => 'failed', 'last_payment_error' => ['message' => 'Card declined']]])->assertOk();

        $payment->refresh();
        $this->assertSame('failed', $payment->status);
        $this->assertSame('Card declined', $payment->failure_reason);
        $this->assertNotNull($payment->failed_at);
    }

    public function test_refund_revokes_the_period_it_bought(): void
    {
        Mail::fake();
        $this->remote();
        [$user, $payment] = $this->pending();
        $this->hook('checkout_session.payment.paid', ['id' => 'cs_1'])->assertOk();
        $this->assertSame('active', $user->fresh()->subscription_status);

        $this->hook('payment.refunded', ['id' => 'pay_1', 'type' => 'payment', 'attributes' => ['status' => 'refunded', 'amount' => 49900]])->assertOk();

        $payment->refresh();
        $user->refresh();
        $this->assertSame('refunded', $payment->status);
        $this->assertSame(499, $payment->refunded_amount);
        $this->assertSame('expired', $user->subscription_status);
        $this->assertSame($this->freePlan()->id, $user->subscription_plan_id);
        $this->assertNull($user->current_period_end);
        $this->assertTrue(AuditLog::query()->where('action', 'payment.refund')->exists());

        // A refund.updated for the same money afterwards changes nothing.
        $this->hook('payment.refund.updated', ['id' => 'ref_1', 'type' => 'refund', 'attributes' => ['payment_id' => 'pay_1', 'amount' => 49900, 'status' => 'succeeded']])->assertOk();
        $this->assertSame(499, $payment->fresh()->refunded_amount);
    }

    public function test_amount_mismatch_is_logged_and_not_applied(): void
    {
        Log::spy();
        $this->remote(100);
        [$user, $payment] = $this->pending();

        $this->hook('checkout_session.payment.paid', ['id' => 'cs_1'])->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('amount_mismatch', $payment->fresh()->failure_reason);
        $this->assertSame('trial', $user->fresh()->subscription_status);
        $this->assertTrue(AuditLog::query()->where('action', 'payment.amount_mismatch')->exists());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'amount does not match'))->once();
    }

    public function test_checkout_errors_are_generic_for_the_client(): void
    {
        Http::fake(['api.paymongo.com/*' => Http::response(['errors' => [['detail' => 'secret internal detail']]], 400)]);
        $plan = $this->paidPlan();

        $this->actingAs($this->member(), 'web')->postJson('/api/v1/payments/checkout', ['plan_id' => $plan->id])
            ->assertStatus(502)
            ->assertJsonMissing(['message' => 'secret internal detail']);
    }
}
