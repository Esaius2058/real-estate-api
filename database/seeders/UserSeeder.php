<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            [
                'name'      => 'Hillary Admin',
                'email'     => 'admin@gmail.com',
                'password'  => Hash::make('password123'),
                'role'      => 'admin',
                'agency_id' => 1, 
            ],
            [
                'name'      => 'Jane Doe',
                'email'     => 'jane.doe@gmail.com',
                'password'  => Hash::make('password123'),
                'role'      => 'agent',
                'agency_id' => 2, 
            ],
            [
                'name'      => 'John Smith',
                'email'     => 'john.smith@gmail.com',
                'password'  => Hash::make('password123'),
                'role'      => 'agent',
                'agency_id' => 2, // Represents an unassigned agent
            ]
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']], 
                $user
            );
        }
    }
}