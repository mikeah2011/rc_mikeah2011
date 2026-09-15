<?php

namespace App\Console\Commands;

use App\Models\Endpoint;
use Illuminate\Console\Command;

class UpsertEndpoint extends Command
{
    protected $signature = 'notifications:endpoint-upsert
        {key}
        {--vendor=}
        {--url=}
        {--method=POST}
        {--timeout=10}
        {--attempts=8}
        {--allowed-header=*}';

    protected $description = 'Create or update a controlled supplier endpoint';

    public function handle(): int
    {
        $existing = Endpoint::query()->where('key', $this->argument('key'))->first();
        $vendor = $this->option('vendor') ?: $existing?->vendor;
        $url = $this->option('url') ?: $existing?->url;
        $method = strtoupper((string) $this->option('method'));
        $timeout = (int) $this->option('timeout');
        $attempts = (int) $this->option('attempts');

        if (! $vendor || ! $url || ! filter_var($url, FILTER_VALIDATE_URL)
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || ! parse_url($url, PHP_URL_HOST)
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null) {
            $this->error('Vendor and a valid HTTPS URL are required.');

            return self::FAILURE;
        }

        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) || $timeout < 1 || $timeout > 30 || $attempts < 1 || $attempts > 50) {
            $this->error('Invalid method, timeout (1-30), or attempts (1-50).');

            return self::FAILURE;
        }

        $headers = $existing?->static_headers ?? [];
        if ($this->input->isInteractive() && $this->confirm('Replace encrypted static headers?', ! $existing)) {
            $json = $this->secret('Static headers JSON (input is hidden)') ?: '{}';
            $decoded = json_decode($json, true);
            if (! is_array($decoded) || ! $this->validHeaders($decoded)) {
                $this->error('Headers must be a JSON object containing only string values.');

                return self::FAILURE;
            }
            $headers = $decoded;
        }

        $allowedHeaders = $this->option('allowed-header');
        if ($existing && $allowedHeaders === []) {
            $allowedHeaders = $existing->allowed_dynamic_headers;
        }

        Endpoint::query()->updateOrCreate(['key' => $this->argument('key')], [
            'vendor' => $vendor,
            'url' => $url,
            'method' => $method,
            'static_headers' => $headers,
            'allowed_dynamic_headers' => array_values(array_unique(array_map('strtolower', $allowedHeaders))),
            'timeout_seconds' => $timeout,
            'max_attempts' => $attempts,
            'is_active' => true,
        ]);

        $this->info('Endpoint saved.');

        return self::SUCCESS;
    }

    private function validHeaders(array $headers): bool
    {
        $forbidden = ['host', 'content-length', 'transfer-encoding', 'connection'];

        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! is_string($value)
                || strpbrk($name.$value, "\r\n") !== false
                || in_array(strtolower($name), $forbidden, true)) {
                return false;
            }
        }

        return true;
    }
}
