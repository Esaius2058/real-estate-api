<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Property;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PropertySeeder extends Seeder
{
    public function run(): void
    {
        // Ensure we have a user and agency to attach these to
        $user = User::first();
        
        if (!$user) {
            $this->command->warn('No users found. Please create a user/agency first before seeding properties.');
            return;
        }

        $agencyId = $user->agency_id ?? 1; // Fallback to 1 if agency_id isn't directly on the user model

        $properties = [
            [
                'agency_id' => $agencyId,
                'user_id' => $user->id,
                'title' => 'The Oribi Penthouse',
                'price' => 85000000,
                'location' => 'Muthaiga',
                'city' => 'Nairobi',
                'bedrooms' => 4,
                'baths' => 5,
                'sqft' => 4200,
                'description' => 'An exquisite full-floor penthouse offering panoramic views of the Karura Forest. Features include floor-to-ceiling windows, a modern chef\'s kitchen with imported Italian marble, a private plunge pool, and complete smart-home automation.',
                'status' => 'Active',
                'contract_end_date' => Carbon::now()->addMonths(6),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'agency_id' => $agencyId,
                'user_id' => $user->id,
                'title' => 'Kitisuru Modern Villa',
                'price' => 120000000,
                'location' => 'Kitisuru',
                'city' => 'Nairobi',
                'bedrooms' => 6,
                'baths' => 6,
                'sqft' => 7500,
                'description' => 'A contemporary masterpiece sitting on 0.5 acres. Boasts a double-volume living area, a tiered home theater, a fully equipped gym, and a landscaped garden with an infinity pool.',
                'status' => 'Active',
                'contract_end_date' => Carbon::now()->addMonths(3),
                'created_at' => Carbon::now()->subDays(5),
                'updated_at' => Carbon::now()->subDays(5),
            ],
            [
                'agency_id' => $agencyId,
                'user_id' => $user->id,
                'title' => 'Nyali Beachfront Apartment',
                'price' => 35000000,
                'location' => 'Nyali',
                'city' => 'Mombasa',
                'bedrooms' => 3,
                'baths' => 3,
                'sqft' => 2100,
                'description' => 'Luxury 3-bedroom apartment with direct beach access. Enjoy uninterrupted Indian Ocean views from your private balcony. The complex includes a communal pool, 24/7 security, and backup generators.',
                'status' => 'Under Contract',
                'contract_end_date' => Carbon::now()->addDays(14),
                'created_at' => Carbon::now()->subWeeks(2),
                'updated_at' => Carbon::now()->subDays(1),
            ],
            [
                'agency_id' => $agencyId,
                'user_id' => $user->id,
                'title' => 'Kilimani Executive Duplex',
                'price' => 28500000,
                'location' => 'Kilimani',
                'city' => 'Nairobi',
                'bedrooms' => 4,
                'baths' => 4,
                'sqft' => 2800,
                'description' => 'Spacious duplex located in the heart of Kilimani. High-end finishes throughout, including mahogany flooring and bespoke cabinetry. Walking distance to major shopping malls and international schools.',
                'status' => 'Closed',
                'contract_end_date' => Carbon::now()->subDays(10),
                'created_at' => Carbon::now()->subMonths(2),
                'updated_at' => Carbon::now()->subDays(10),
            ],
            [
                'agency_id' => $agencyId,
                'user_id' => $user->id,
                'title' => 'Milimani Commercial Plot',
                'price' => 45000000,
                'location' => 'Milimani',
                'city' => 'Nakuru',
                'bedrooms' => 0,
                'baths' => 0,
                'sqft' => 10890, // Approx 1/4 acre
                'description' => 'Prime 1/4 acre commercial plot ideal for a high-rise office block or premium apartments. Clean title deed and easily accessible via the main highway.',
                'status' => 'Active',
                'contract_end_date' => Carbon::now()->addMonths(12),
                'created_at' => Carbon::now()->subDays(2),
                'updated_at' => Carbon::now()->subDays(2),
            ],
        ];

        DB::table('properties')->insert($properties);

        $this->command->info('Successfully seeded 5 premium Kenyan properties!');
    }
}