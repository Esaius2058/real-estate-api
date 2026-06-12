<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name'      => 'Hillary Sioliula',
                'email'     => 'hillary@makao.co.ke',
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
                'agency_id' => 2, 
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