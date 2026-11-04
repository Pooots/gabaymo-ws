<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\KnowledgeDocument;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\Request;

class AdminOverviewController extends Controller
{
    public function show()
    {
        $start = now()->subDays(13)->startOfDay();
        $counts = User::query()
            ->where('role', 'member')
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $signups = [];
        for ($i = 0; $i < 14; $i++) {
            $day = $start->copy()->addDays($i);
            $key = $day->toDateString();
            $signups[] = [
                'label' => $day->format('M j'),
                'value' => (int) ($counts[$key] ?? 0),
            ];
        }

        return response()->json([
            'members' => User::query()->where('role', 'member')->count(),
            'partners' => User::query()->where('type', 'partners')->count(),
            'open_tickets' => SupportTicket::query()->where('status', 'open')->count(),
            'documents' => KnowledgeDocument::query()
                ->where('active', true)
                ->where('source_status', 'approved')
                ->count(),
            'payments_total' => (int) SubscriptionPayment::query()->where('status', 'paid')->sum('amount'),
            'signups' => $signups,
            'plans' => $this->plans(),
            'activity' => $this->activity(),
        ]);
    }

    public function audit()
    {
        return response()->json([
            'logs' => $this->activity(30),
        ]);
    }

    public static function record(Request $request, string $action, string $summary): void
    {
        $user = $request->user();
        AuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'summary' => $summary,
        ]);
    }

    private function plans(): array
    {
        return SubscriptionPlan::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => [
                'name' => $plan->name,
                'price' => $plan->priceLabel(),
                'cadence' => $plan->cadenceLabel(),
                'tokens' => $plan->tokens,
                'subscribers' => 0,
            ])
            ->all();
    }

    private function activity(int $limit = 6): array
    {
        return AuditLog::query()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'summary' => $log->summary,
                'at' => $log->created_at?->timezone(config('app.timezone'))->toIso8601String(),
            ])
            ->all();
    }
}
