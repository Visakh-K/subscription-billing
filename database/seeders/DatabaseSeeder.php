<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\UsageEvent;
use App\Services\SubscriptionService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Merchant (Acme Corp) with a fixed API Key for Postman
        $merchant = Merchant::updateOrCreate(
            ['name' => 'Acme Corp'],
            ['api_key' => 'test_api_key_123']
        );

        // 2. Create Plans
        $starterPlan = Plan::updateOrCreate(
            ['merchant_id' => $merchant->id, 'name' => 'Starter'],
            [
                'base_price' => 2000.00,
                'billing_cycle' => 'monthly',
                'included_units' => 20000,
                'overage_rate' => 0.75,
            ]
        );

        $growthPlan = Plan::updateOrCreate(
            ['merchant_id' => $merchant->id, 'name' => 'Growth'],
            [
                'base_price' => 5000.00,
                'billing_cycle' => 'monthly',
                'included_units' => 42000,
                'overage_rate' => 0.50,
            ]
        );

        // 3. Create Customers & Subscriptions matching the wireframe
        $subService = app(SubscriptionService::class);
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        $customersData = [
            ['name' => 'Beta Retail Pvt Ltd', 'email' => 'billing@betaretail.com', 'this_month' => 38200, 'last_month' => 35000],
            ['name' => 'Craft Foods Co.', 'email' => 'accounts@craftfoods.com', 'this_month' => 31050, 'last_month' => 30000],
            ['name' => 'Delta Mart', 'email' => 'finance@deltamart.com', 'this_month' => 27900, 'last_month' => 28000],
            ['name' => 'Nova Traders', 'email' => 'ops@novatraders.com', 'this_month' => 15200, 'last_month' => 40000], // 62% drop (Churn Risk)
            ['name' => 'QuickMart', 'email' => 'tech@quickmart.com', 'this_month' => 13500, 'last_month' => 30000],    // 55% drop (Churn Risk)
        ];

        foreach ($customersData as $index => $data) {
            $customer = Customer::updateOrCreate(
                ['merchant_id' => $merchant->id, 'email' => $data['email']],
                ['name' => $data['name']]
            );

            $subService->createSubscription($customer, $growthPlan, $periodStart, $periodStart, $periodEnd);

            // Seed previous month usage (for MoM Churn Risk comparison)
            UsageEvent::updateOrCreate(
                ['idempotency_key' => "seed_last_month_{$customer->id}"],
                [
                    'merchant_id' => $merchant->id,
                    'customer_id' => $customer->id,
                    'units' => $data['last_month'],
                    'recorded_at' => now()->subMonthNoOverflow()->midDay(),
                ]
            );

            // Seed current month usage
            UsageEvent::updateOrCreate(
                ['idempotency_key' => "seed_this_month_{$customer->id}"],
                [
                    'merchant_id' => $merchant->id,
                    'customer_id' => $customer->id,
                    'units' => $data['this_month'],
                    'recorded_at' => now()->subDays($index + 1),
                ]
            );
        }
    }
}
