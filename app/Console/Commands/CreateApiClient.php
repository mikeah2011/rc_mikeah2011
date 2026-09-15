<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateApiClient extends Command
{
    protected $signature = 'notifications:client-create {name}';

    protected $description = 'Create an API client and print its key once';

    public function handle(): int
    {
        if (ApiClient::query()->where('name', $this->argument('name'))->exists()) {
            $this->error('A client with this name already exists.');

            return self::FAILURE;
        }

        $plain = 'rc_'.Str::random(48);
        ApiClient::query()->create([
            'name' => $this->argument('name'),
            'key_hash' => hash('sha256', $plain),
            'is_active' => true,
        ]);

        $this->warn('Store this key now; it will not be shown again:');
        $this->line($plain);

        return self::SUCCESS;
    }
}
