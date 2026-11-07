<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\KnowledgeDocument;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\SupportTicket;
use App\Models\TokenUsageEvent;
use App\Models\User;
use App\Support\Paginates;
use Illuminate\Http\Request;

class AdminOverviewController extends Controller
{
    use Paginates;

    public function show()
    {
        $start = now()->subDays(13)->startOfDay();
        $counts = User::query()
            ->customers()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $usage = TokenUsageEvent::query()
            ->where('type', 'usage')
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as questions, -SUM(tokens) as tokens')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $signups = [];
        for ($i = 0; $i < 14; $i++) {
            $day = $start->copy()->addDays($i);
            $key = $day->toDateString();
            $signups[] = [
                'date' => $key,
                'label' => $day->format('M j'),
                'value' => (int) ($counts[$key] ?? 0),
                'questions' => (int) ($usage[$key]->questions ?? 0),
                'tokens' => max(0, (int) ($usage[$key]->tokens ?? 0)),
            ];
        }

        $weekAgo = now()->subDays(7);
        $paid = SubscriptionPayment::query()->where('status', 'paid');

        return response()->json([
            'members' => User::query()->customers()->count(),
            'members_new_7d' => User::query()->customers()->where('created_at', '>=', $weekAgo)->count(),
            'partners' => User::query()->partners()->count(),
            'open_tickets' => SupportTicket::query()->where('status', 'open')->count(),
            'tickets_new_7d' => SupportTicket::query()->where('created_at', '>=', $weekAgo)->count(),
            'documents' => KnowledgeDocument::query()
                ->where('active', true)
                ->where('source_status', 'approved')
                ->count(),
            'documents_pending' => KnowledgeDocument::query()
                ->where('source_status', '!=', 'approved')
                ->count(),
            'payments_total' => (int) (clone $paid)->sum('amount'),
            'payments_count' => (clone $paid)->count(),
            'tokens_used_14d' => array_sum(array_column($signups, 'tokens')),
            'questions_14d' => array_sum(array_column($signups, 'questions')),
            'signups' => $signups,
            'plans' => $this->plans(),
            'activity' => $this->activity(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function audit(Request $request)
    {
        $query = AuditLog::query()->latest('id');
        $this->search($request, $query, ['action', 'summary']);
        if ($action = $request->query('action')) {
            $query->where('action', 'like', str_replace('%', '', (string) $action).'%');
        }

        return response()->json($this->paginate($request, $query, fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'summary' => $log->summary,
            'user_id' => $log->user_id,
            'admin_user_id' => $log->admin_user_id,
            'at' => $log->created_at?->timezone(config('app.timezone'))->toIso8601String(),
        ], 'logs'));
    }

    public static function record(Request $request, string $action, string $summary): void
    {
        $actor = $request->user();
        AuditLog::query()->create([
            'user_id' => $actor instanceof User ? $actor->id : null,
            'admin_user_id' => $actor instanceof AdminUser ? $actor->id : null,
            'action' => $action,
            'summary' => mb_substr($summary, 0, 250),
        ]);
    }

    private function plans(): array
    {
        $subscribers = User::query()
            ->customers()
            ->whereNotNull('subscription_plan_id')
            ->selectRaw('subscription_plan_id, COUNT(*) as total')
            ->groupBy('subscription_plan_id')
            ->pluck('total', 'subscription_plan_id');

        return SubscriptionPlan::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => [
                'name' => $plan->name,
                'price' => $plan->priceLabel(),
                'cadence' => $plan->cadenceLabel(),
                'token_allowance' => $plan->token_allowance,
                'allowance_period' => $plan->allowance_period,
                'estimated_questions' => $plan->estimatedQuestions(),
                'subscribers' => (int) ($subscribers[$plan->id] ?? 0),
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
