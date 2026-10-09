<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo accounts. CHANGE THESE PASSWORDS before the system is used for real.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::$auditingEnabled = false;

        $users = [
            ['name' => 'Andrea Villanueva', 'username' => 'manager', 'email' => 'manager@heim.cafe', 'role' => 'manager', 'password' => 'Manager@123', 'pin' => '1234', 'phone' => '0917 555 0101'],
            ['name' => 'Miguel Santos', 'username' => 'manager2', 'email' => 'miguel@heim.cafe', 'role' => 'manager', 'password' => 'Manager@123', 'pin' => '5678', 'phone' => '0917 555 0102'],
            ['name' => 'Bea Ramirez', 'username' => 'staff', 'email' => 'staff@heim.cafe', 'role' => 'staff', 'password' => 'Staff@123', 'pin' => null, 'phone' => '0917 555 0201'],
            ['name' => 'Carlo Mendoza', 'username' => 'carlo', 'email' => 'carlo@heim.cafe', 'role' => 'staff', 'password' => 'Staff@123', 'pin' => null, 'phone' => '0917 555 0202'],
            ['name' => 'Jasmine Uy', 'username' => 'jasmine', 'email' => 'jasmine@heim.cafe', 'role' => 'staff', 'password' => 'Staff@123', 'pin' => null, 'phone' => '0917 555 0203'],
        ];

        foreach ($users as $data) {
            User::create($data + ['is_active' => true, 'email_verified_at' => now()]);
        }

        User::$auditingEnabled = true;
    }
}
