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
                'agency_id' => 2,
                'agent_id' => 3, // Replaced 'user_id' with 'agent_id'
                'name' => 'John Smith',
                'email' => 'john.smith@example.com', // Added required email
                'phone' => '+254700000001',
                'value' => 150000, // Replaced 'max_budget' with 'value'
                'kanban_stage' => 'offer', // 'negotiating' is not in your enum, use 'offer' or 'contacted'
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'agency_id' => 2,
                'agent_id' => 2, // Replaced 'user_id' with 'agent_id'
                'name' => 'Jane Doe',
                'email' => 'jane.doe@example.com', // Added required email
                'phone' => '+254700000002',
                'value' => 100000, // Replaced 'max_budget' with 'value'
                'kanban_stage' => 'showing', // 'viewing' is not in your enum, use 'showing'
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}