<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use App\Support\Paginates;
use Illuminate\Http\Request;

class AdminPaymentController extends Controller
{
    use Paginates;

    public function index(Request $request)
    {
        $query = SubscriptionPayment::query()->with('plan', 'user')->latest('id');
        if ($status = $request->query('status')) {
            $query->where('status', (string) $status);
        }
        $term = trim((string) $request->query('search', ''));
        if ($term !== '') {
            $like = '%'.str_replace('%', '', mb_substr($term, 0, 100)).'%';
            $query->where(fn ($q) => $q->where('reference', 'like', $like)
                ->orWhere('receipt_number', 'like', $like)
                ->orWhereHas('user', fn ($u) => $u->withTrashed()->where('email', 'like', $like)->orWhere('name', 'like', $like)));
        }

        return response()->json($this->paginate($request, $query, fn (SubscriptionPayment $p) => $p->present() + [
            'member' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name, 'email' => $p->user->email] : null,
            'paymongo_checkout_id' => $p->paymongo_checkout_id,
            'failure_reason' => $p->failure_reason,
            'disputed_at' => $p->disputed_at?->toIso8601String(),
        ]));
    }

    public function summary()
    {
        $count = fn (string $status) => SubscriptionPayment::query()->where('status', $status)->count();
        $paidLike = ['paid', 'partially_refunded', 'disputed'];
        $net = fn ($query) => (int) $query->whereIn('status', $paidLike)->sum('amount') - (int) (clone $query)->whereIn('status', $paidLike)->sum('refunded_amount');

        return response()->json([
            'revenue_month' => $net(SubscriptionPayment::query()->where('paid_at', '>=', now()->startOfMonth())),
            'revenue_total' => $net(SubscriptionPayment::query()),
            'paid_count' => $count('paid'),
            'pending_count' => $count('pending'),
            'failed_count' => $count('failed'),
            'refunded_count' => SubscriptionPayment::query()->whereIn('status', ['refunded', 'partially_refunded'])->count(),
        ]);
    }
}
