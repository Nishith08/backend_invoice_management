<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UserRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $roles = [
            'admin',
            'accounts_1st',
            'accounts_2nd',
            'accounts_3rd',
            'final_accountant',
        ];

        foreach ($roles as $role) {
            User::create([
                'name' => ucfirst(str_replace('_', ' ', $role)),
                'email' => $role . '@test.com',
                'password' => Hash::make('password'), // Default password
                'role' => $role,
            ]);
        }
    }
}
