<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $user = User::query()->updateOrCreate(
                ['email' => 'demo@brandvault.dev'],
                [
                    'name' => 'Demo User',
                    'password' => 'Demo1234!',
                ]
            );

            Workspace::query()->updateOrCreate(
                ['user_id' => $user->id],
                ['name' => 'Demo Workspace']
            );
        });
    }
}
