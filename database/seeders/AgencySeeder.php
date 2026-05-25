<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Agency;

class AgencySeeder extends Seeder
{
    public function run(): void
    {
        Agency::updateOrCreate(
            ['id' => 1],
            ['name' => 'Makao Real Estate HQ', 'location' => 'Nairobi, Kenya']
        );

        Agency::updateOrCreate(
            ['id' => 2],
            ['name' => 'Coastal Properties Ltd', 'location' => 'Mombasa, Kenya']
        );
    }
}