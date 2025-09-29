<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'superadmin@taxidispatch.com'], // check if already exists
            [
                'name' => 'Super Admin',
                'password' => Hash::make('taxidispatch@123'), // change this to secure password
                'role' => 'superadmin',
            ]
        );
    }
}
