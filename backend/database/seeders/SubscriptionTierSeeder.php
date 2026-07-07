<?php

namespace Database\Seeders;

use App\Models\SubscriptionTier;
use Illuminate\Database\Seeder;

class SubscriptionTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'slug' => 'starter',
                'name' => 'Agent Starter',
                'monthly_price' => 1500,
                'yearly_price' => 15300,
                'max_properties' => 10,
                'is_active' => true,
                'features' => [
                    'Up to 10 active listings',
                    'Basic lead management',
                    'Escrow tracking',
                ],
            ],
            [
                'slug' => 'growth',
                'name' => 'Agency Growth',
                'monthly_price' => 3500,
                'yearly_price' => 35700,
                'max_properties' => 50,
                'is_active' => true,
                'features' => [
                    'Up to 50 active listings',
                    'Full lead management',
                    'Escrow tracking',
                    'KYC vault access',
                ],
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Agency Enterprise',
                'monthly_price' => 7500,
                'yearly_price' => 76500,
                'max_properties' => 999999,
                'is_active' => true,
                'features' => [
                    'Unlimited active listings',
                    'Full lead management',
                    'Escrow tracking',
                    'KYC vault access',
                    'Priority support',
                ],
            ],
        ];

        foreach ($tiers as $tier) {
            SubscriptionTier::updateOrCreate(['slug' => $tier['slug']], $tier);
        }
    }
}