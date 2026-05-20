<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LeadSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('leads')->insert([
            [
                'name' => 'John Doe',
                'user_id' => 3,
                'kanban_stage' => 'negotiating',
                'desired_location' => 'Nairobi',
                'max_budget' => 150000,
                'agency_id' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Jane Smith',
                'user_id' => 4,
                'kanban_stage' => 'viewing',
                'desired_location' => 'Nairobi',
                'max_budget' => 100000,
                'agency_id' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}