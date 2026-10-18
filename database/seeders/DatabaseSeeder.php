<?php

namespace Database\Seeders;

use App\Actions\ChatService;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(ChatService $chat): void
    {
        $sara = User::factory()->create(['name' => 'Sara Ahmed', 'email' => 'sara@example.com']);
        $omar = User::factory()->create(['name' => 'Omar Khalid', 'email' => 'omar@example.com']);
        $team = User::factory()->count(3)->create();

        $direct = $chat->openDirect($sara, $omar);
        $chat->send($direct, $sara, 'Hi Omar, did you get the site report?');
        $chat->send($direct, $omar, 'Yes, reviewing it now.');

        $group = $chat->createGroup($sara, 'Operations team', [$omar->id, ...$team->pluck('id')->all()]);
        $chat->send($group, $sara, 'Welcome to the operations group 👋');
    }
}
