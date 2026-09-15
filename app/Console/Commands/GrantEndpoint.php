<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use App\Models\Endpoint;
use Illuminate\Console\Command;

class GrantEndpoint extends Command
{
    protected $signature = 'notifications:client-grant {client} {endpoint}';

    protected $description = 'Allow an API client to use an endpoint';

    public function handle(): int
    {
        $client = ApiClient::query()->where('name', $this->argument('client'))->first();
        $endpoint = Endpoint::query()->where('key', $this->argument('endpoint'))->first();

        if (! $client || ! $endpoint) {
            $this->error('Client or endpoint was not found.');

            return self::FAILURE;
        }

        $client->endpoints()->syncWithoutDetaching([$endpoint->id]);
        $this->info('Endpoint granted.');

        return self::SUCCESS;
    }
}
