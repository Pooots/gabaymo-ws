<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free Trial',
                'price' => 0,
                'cadence' => 'forever',
                // ~20 typical questions, once.
                'token_allowance' => 200_000,
                'allowance_period' => 'once',
                'summary' => 'Try the assistant with full citations on a small set of questions.',
                'specs' => [
                    'Trial usage for about 20 questions',
                    'Full citation cards with page numbers',
                    'Knowledge library search',
                    'No partner lawyer support',
                ],
                'featured' => false,
                'active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Monthly Membership',
                'price' => 499,
                'cadence' => 'month',
                // ~300 typical questions a month.
                'token_allowance' => 3_000_000,
                'allowance_period' => 'month',
                'summary' => 'For school heads who consult policies week to week.',
                'specs' => [
                    'Usage for about 300 questions, refreshed monthly',
                    'Citation cards with page numbers',
                    'Partner lawyer support tickets',
                    'Usage meter and full history',
                ],
                'featured' => false,
                'active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Yearly Membership',
                'price' => 4990,
                'cadence' => 'year',
                // Billed yearly, refreshed monthly (~360 questions a month).
                'token_allowance' => 3_600_000,
                'allowance_period' => 'month',
                'summary' => 'Twelve months of membership for the price of ten.',
                'specs' => [
                    '20% more monthly usage than Monthly',
                    'Two months free',
                    'Priority partner lawyer support',
                    'Everything in Monthly',
                ],
                'featured' => true,
                'active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::query()->updateOrCreate(
                ['name' => $plan['name']],
                $plan,
            );
        }
    }
}
