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
        // Target an agent belonging to Agency 2 first, fallback to first available user
        $user = User::where('agency_id', 2)->first() ?? User::first();
        
        if (!$user) {
            $this->command->warn('No users found. Please seed users/agencies before running the PropertySeeder.');
            return;
        }

        // Hardcode target agency ID to 2 as requested
        $agencyId = 2; 

        $properties = [
            [
                'agency_id'         => $agencyId,
                'user_id'           => $user->id,
                'title'             => 'The Oribi Penthouse',
                'price'             => 85000000.00,
                'location'          => 'Muthaiga',
                'city'              => 'Nairobi',
                'bedrooms'          => 4,
                'baths'             => 5,
                'sqft'              => 4200,
                'description'       => 'An exquisite full-floor penthouse offering panoramic views of the Karura Forest. Features include floor-to-ceiling windows, a modern chef\'s kitchen with imported Italian marble, a private plunge pool, and complete smart-home automation.',
                'status'            => 'Active',
                'contract_end_date' => Carbon::now()->addMonths(6),
                'created_at'        => Carbon::now(),
                'updated_at'        => Carbon::now(),
            ],
            [
                'agency_id'         => $agencyId,
                'user_id'           => $user->id,
                'title'             => 'Nyali Oceanfront Retreat',
                'price'             => 55000000.00,
                'location'          => 'Nyali Beach',
                'city'              => 'Mombasa',
                'bedrooms'          => 3,
                'baths'             => 4,
                'sqft'              => 3100,
                'description'       => 'Luxury 3-bedroom oceanfront apartment with direct beach access. Enjoy uninterrupted Indian Ocean views from an expansive wrap-around private balcony. Includes a communal infinity pool, 24/7 security, and an automated backup generator.',
                'status'            => 'Active',
                'contract_end_date' => Carbon::now()->addMonths(4),
                'created_at'        => Carbon::now()->subDays(3),
                'updated_at'        => Carbon::now()->subDays(3),
            ],
            [
                'agency_id'         => $agencyId,
                'user_id'           => $user->id,
                'title'             => 'Riat Hills Lakeside Villa',
                'price'             => 38000000.00,
                'location'          => 'Riat Hills',
                'city'              => 'Kisumu',
                'bedrooms'          => 5,
                'baths'             => 5,
                'sqft'              => 4600,
                'description'       => 'An architectural masterpiece nestled on the exclusive Riat Hills. Offers breathtaking panoramic views of Lake Victoria and Kisumu city. Features multi-level terraces, solar water heating, and high-end mahogany finishes.',
                'status'            => 'Under Contract',
                'contract_end_date' => Carbon::now()->addDays(30),
                'created_at'        => Carbon::now()->subWeeks(2),
                'updated_at'        => Carbon::now()->subDays(1),
            ],
            [
                'agency_id'         => $agencyId,
                'user_id'           => $user->id,
                'title'             => 'Milimani Executive Duplex',
                'price'             => 24500000.00,
                'location'          => 'Milimani',
                'city'              => 'Nakuru',
                'bedrooms'          => 4,
                'baths'             => 4,
                'sqft'              => 3200,
                'description'       => 'Spacious modern duplex located in Nakuru\'s premier residential zone. Double-volume lounge layout with views of Lake Nakuru National Park. Private detached staff quarters (DSQ) and secure perimeter fencing included.',
                'status'            => 'Closed',
                'contract_end_date' => Carbon::now()->subDays(15),
                'created_at'        => Carbon::now()->subMonths(1),
                'updated_at'        => Carbon::now()->subDays(15),
            ],
            [
                'agency_id'         => $agencyId,
                'user_id'           => $user->id,
                'title'             => 'Mount Kenya Wildlife Estate Chalet',
                'price'             => 68000000.00,
                'location'          => 'Mount Kenya Wildlife Estate',
                'city'              => 'Nanyuki',
                'bedrooms'          => 4,
                'baths'             => 4,
                'sqft'              => 4100,
                'description'       => 'Premium holiday home set inside a secure wildlife conservancy footprint. Offers unobstructed, crisp views of Mount Kenya. Game tracks wrap around the property. Ideal for high-yielding short-term vacation rentals.',
                'status'            => 'Active',
                'contract_end_date' => Carbon::now()->addMonths(12),
                'created_at'        => Carbon::now()->subDays(1),
                'updated_at'        => Carbon::now()->subDays(1),
            ],
        ];

        DB::table('properties')->insert($properties);

        $this->command->info('Successfully seeded 5 diverse Kenyan properties for Agency ID 2!');
    }
}