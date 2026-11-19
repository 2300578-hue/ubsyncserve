<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminPassword = config('admin.password');
        if (! is_string($adminPassword) || mb_strlen($adminPassword) < 12) {
            throw new RuntimeException('Set ADMIN_PASSWORD to a value of at least 12 characters before running the database seeder.');
        }

        User::firstOrCreate(
            ['email' => config('admin.email')],
            [
                'name' => config('admin.name'),
                'password' => $adminPassword,
                'role' => 'manager',
                'email_verified_at' => now(),
            ],
        );

        $this->call(ProductSeeder::class);
    }
}
