<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        // users has no "name" column - display_name lives on user_profiles, which is NOT
        // NULL, so a seeded user with no profile row fails the very next feature that reads
        // one. No UserProfile model exists yet (that is a later plan's concern, same as the
        // rest of app/Infrastructure/Persistence), so this is a plain insert.
        DB::table('user_profiles')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'display_name' => 'Test User',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
