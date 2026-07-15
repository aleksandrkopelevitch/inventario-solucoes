<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@leomadeiras.com.br'],
            [
                'name'     => 'Admin',
                'role'     => UserRole::Admin->value,
                'password' => 'password',
            ],
        );

        $this->call([
            AttributeOptionSeeder::class,
            SolutionSeeder::class,
            IntegrationSeeder::class,
            FlowspecExampleSeeder::class,
        ]);
    }
}
