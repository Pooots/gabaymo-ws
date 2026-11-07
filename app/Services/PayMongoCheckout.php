<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class PayMongoCheckout
{
    private const BASE = 'https://api.paymongo.com/v1';

    public function createSession(array $attributes): array
    {
        $response = Http::withBasicAuth($this->secret(), '')
            ->acceptJson()
            ->timeout(20)
            ->post(self::BASE.'/checkout_sessions', [
                'data' => ['attributes' => $attributes],
            ]);

        if ($response->failed()) {
            $detail = collect($response->json('errors') ?? [])
                ->map(fn ($error) => is_array($error) ? ($error['detail'] ?? null) : null)
                ->filter()
                ->implode(' ');
            throw new RuntimeException($detail !== '' ? $detail : 'PayMongo could not open the checkout.');
        }

        $data = $response->json('data');
        $id = $data['id'] ?? null;
        $url = $data['attributes']['checkout_url'] ?? null;
        if (! is_string($id) || ! is_string($url) || $url === '') {
            throw new RuntimeException('PayMongo could not open the checkout.');
        }

        return [
            'id' => $id,
            'checkout_url' => $url,
            'payment_intent_id' => $data['attributes']['payment_intent']['id'] ?? null,
        ];
    }

    public function status(string $checkoutId): array
    {
        $response = Http::withBasicAuth($this->secret(), '')
            ->acceptJson()
            ->timeout(20)
            ->get(self::BASE.'/checkout_sessions/'.$checkoutId);

        if ($response->failed()) {
            throw new RuntimeException('PayMongo could not confirm the payment.');
        }

        $attributes = $response->json('data.attributes') ?? [];
        $status = 'pending';
        $intent = $attributes['payment_intent']['attributes']['status'] ?? null;
        if ($intent === 'succeeded') {
            $status = 'succeeded';
        } elseif (in_array($intent, ['failed', 'cancelled'], true)) {
            $status = 'failed';
        }

        $paymentId = null;
        foreach ($attributes['payments'] ?? [] as $payment) {
            if (($payment['attributes']['status'] ?? null) === 'paid') {
                $status = 'succeeded';
                $paymentId = $payment['id'] ?? null;
                break;
            }
        }

        if ($status === 'pending') {
            $status = match ($attributes['status'] ?? 'active') {
                'paid' => 'succeeded',
                'expired' => 'failed',
                default => 'pending',
            };
        }

        $amount = 0;
        foreach ($attributes['line_items'] ?? [] as $item) {
            $amount += (int) ($item['amount'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1));
        }

        return [
            'status' => $status,
            'amount_centavos' => $amount,
            'payment_id' => is_string($paymentId) ? $paymentId : null,
            'payment_intent_id' => $attributes['payment_intent']['id'] ?? null,
        ];
    }

    public function signatureValid(string $payload, ?string $header): bool
    {
        $secret = trim((string) config('services.paymongo.webhook_secret'));
        if ($secret === '' || ! is_string($header) || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, null);
            if (is_string($key) && is_string($value) && $key !== '') {
                $parts[$key] = $value;
            }
        }

        $timestamp = $parts['t'] ?? null;
        $expected = config('services.paymongo.env') === 'live'
            ? ($parts['li'] ?? null)
            : ($parts['te'] ?? null);

        if (! is_string($timestamp) || ! is_string($expected) || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $computed = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return hash_equals($expected, $computed);
    }

    private function secret(): string
    {
        $secret = trim((string) config('services.paymongo.secret_key'));
        if ($secret === '') {
            throw new RuntimeException('PayMongo sandbox is not configured.');
        }

        return $secret;
    }
}
