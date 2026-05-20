<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AgencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('agencies')->insert([
            [
                'name' => 'Prime Real Estate',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'City Skyline Properties',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}