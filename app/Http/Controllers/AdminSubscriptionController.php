<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminSubscriptionController extends Controller
{
    public function index()
    {
        return response()->json([
            'plans' => SubscriptionPlan::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (SubscriptionPlan $plan) => $this->present($plan))
                ->all(),
        ]);
    }

    public function catalog()
    {
        return response()->json([
            'plans' => SubscriptionPlan::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (SubscriptionPlan $plan) => $this->present($plan))
                ->all(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $plan = DB::transaction(function () use ($data) {
            $plan = SubscriptionPlan::query()->create($data);
            $this->keepSingleFeatured($plan);

            return $plan->fresh();
        });

        AdminOverviewController::record(
            $request,
            'subscription.create',
            "Added the {$plan->name} subscription",
        );

        return response()->json(['plan' => $this->present($plan)], 201);
    }

    public function update(Request $request, SubscriptionPlan $subscription)
    {
        $data = $this->validated($request);
        $plan = DB::transaction(function () use ($subscription, $data) {
            $subscription->update($data);
            $this->keepSingleFeatured($subscription);

            return $subscription->fresh();
        });

        AdminOverviewController::record(
            $request,
            'subscription.update',
            "Updated the {$plan->name} subscription",
        );

        return response()->json(['plan' => $this->present($plan)]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'price' => ['required', 'integer', 'min:0', 'max:1000000'],
            'cadence' => ['required', Rule::in(['forever', 'month', 'year'])],
            // Weighted tokens per usage period (see config services.usage).
            'token_allowance' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'allowance_period' => ['required', Rule::in(['month', 'once'])],
            'summary' => ['required', 'string', 'max:240'],
            'specs' => ['required', 'array', 'min:1', 'max:8'],
            'specs.*' => ['required', 'string', 'max:120'],
            'featured' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);
    }

    private function keepSingleFeatured(SubscriptionPlan $plan): void
    {
        if (! $plan->featured) {
            return;
        }

        SubscriptionPlan::query()
            ->whereKeyNot($plan->id)
            ->update(['featured' => false]);
    }

    private function present(SubscriptionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'price' => $plan->price,
            'price_label' => $plan->priceLabel(),
            'cadence' => $plan->cadence,
            'cadence_label' => $plan->cadenceLabel(),
            'token_allowance' => $plan->token_allowance,
            'allowance_period' => $plan->allowance_period,
            'estimated_questions' => $plan->estimatedQuestions(),
            'allowance_label' => $plan->allowanceLabel(),
            'summary' => $plan->summary,
            'specs' => array_values($plan->specs ?? []),
            'featured' => $plan->featured,
            'active' => $plan->active,
            'sort_order' => $plan->sort_order,
        ];
    }
}
