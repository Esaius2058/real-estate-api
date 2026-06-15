<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EscrowSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create the master agency with its mandatory join code
        $agencyId = DB::table('agencies')->insertGetId([
            'name'       => 'Makao Agency Platform',
            'join_code'  => 'MK-' . strtoupper(Str::random(6)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Create the authenticated user accounts tied to this agency
        $admin = User::firstOrCreate(
            ['email' => 'admin@realestateos.com'],
            [
                'name'      => 'Hillary Sioliula',
                'password'  => Hash::make('password'),
                'phone'     => '+254700000000',
                'agency_id' => $agencyId
            ]
        );

        $buyer = User::firstOrCreate(
            ['email' => 'buyer@workspace.ke'],
            [
                'name'      => 'Victor Keitany',
                'password'  => Hash::make('password'),
                'phone'     => '+254711223344',
                'agency_id' => $agencyId
            ]
        );

        $seller = User::firstOrCreate(
            ['email' => 'seller@makao.co.ke'],
            [
                'name'      => 'Kiprop Demo Seller',
                'password'  => Hash::make('password'),
                'phone'     => '+254722334455',
                'agency_id' => $agencyId
            ]
        );

        // 3. Create a mock property record to satisfy the foreign key constraint
        // (Note: If your properties table fails on a missing column like 'title' or 'price', 
        // match those specific keys to your properties migration file)
        $propertyId = DB::table('properties')->insertGetId([
            'agency_id'   => $agencyId,
            'user_id'     => $admin->id,
            'title'       => 'Maisonette Plot 4B - Milimani',
            'price'       => 4500000.00,
            'location'    => 'Milimani',
            'city'        => 'Nakuru',
            'bedrooms'    => 3,
            'baths'       => 2,
            'sqft'        => 2400,
            'status'      => 'Active',
            'description' => '',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        // 4. Seed the Live Escrow Transactions using your exact schema keys
        DB::table('escrows')->insert([
            [
                'property_id'  => $propertyId,
                'buyer_id'     => $buyer->id,
                'seller_id'    => $seller->id,
                'agency_id'    => $agencyId,
                'amount'       => 4500000.00,
                'terms'        => 'Standard land sale agreement escrow terms. Balance to be paid upon title inspection.',
                'status'       => 'funded', // This will light up your "Active Escrows" counter
                'funded_at'    => now(),
                'completed_at' => null,
                'created_by'   => $admin->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
            [
                'property_id'  => $propertyId,
                'buyer_id'     => $buyer->id,
                'seller_id'    => $seller->id,
                'agency_id'    => $agencyId,
                'amount'       => 7200000.00,
                'terms'        => 'Suburban Apartment Block structural transfer contract complete.',
                'status'       => 'completed', // This will light up your "Completed Escrows" counter
                'funded_at'    => now()->subDays(15),
                'completed_at' => now()->subDays(2),
                'created_by'   => $admin->id,
                'created_at'   => now()->subDays(15),
                'updated_at'   => now(),
            ]
        ]);
    }
}