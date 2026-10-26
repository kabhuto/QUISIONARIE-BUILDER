<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Safe to run again (e.g. `composer run setup` twice): only create the test user once. Never on
        // a server: its well-known password would open the admin pages to anyone.
        if (app()->environment('local', 'testing') && ! User::query()->where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        $this->call([ReadySurveySeeder::class, DummySurveySeeder::class]);
    }
}
