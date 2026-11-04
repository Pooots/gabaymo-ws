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
                'tokens' => 20,
                'summary' => 'Try the assistant with full citations on a small set of questions.',
                'specs' => [
                    '20 trial questions',
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
                'tokens' => 300,
                'summary' => 'For school heads who consult policies week to week.',
                'specs' => [
                    '300 questions every month',
                    'Citation cards with page numbers',
                    'Partner lawyer support tickets',
                    'Token wallet and full history',
                ],
                'featured' => false,
                'active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Yearly Membership',
                'price' => 4990,
                'cadence' => 'year',
                'tokens' => 3600,
                'summary' => 'Twelve months of membership for the price of ten.',
                'specs' => [
                    '3,600 questions per year',
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
