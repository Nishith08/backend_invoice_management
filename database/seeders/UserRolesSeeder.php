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
            'purchase_office',
        ];
        $names = [
            'marketing',
            'acc_office',
            'cfao',
            'president',
            'acc_office_2',
            'purchase_office',
        ];
        $passwords = [
            'l46ngk#f',  // for admin
            'p3R$z8kL',  // for accounts_1st
            'x9M!n4Vq',  // for accounts_2nd
            'j7T@e1Wb',  // for accounts_3rd
            'h2S#y5Nc',  // for final_accountant
            't2e$X8kL',  // for purchase office
        ];

        $i = 0;
        foreach ($roles as $role) {
            User::create([
                'name' => ucfirst(str_replace('_', ' ', $role)),
                'email' => $names[$i] . '@approvals.com',
                'password' => Hash::make($passwords[$i]),
                'role' => $role,
            ]);
            $i++;
        }
    }
}
