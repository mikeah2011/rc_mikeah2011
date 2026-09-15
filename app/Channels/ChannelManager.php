<?php

namespace App\Channels;

use Illuminate\Support\Arr;

class ChannelManager
{
    protected array $drivers = [
        'http' => HttpChannel::class,
        // other drivers (email/sms) can be added here
    ];

    public function driver(string $name): ChannelContract
    {
        $class = Arr::get($this->drivers, $name);
        if (! $class) {
            throw new \InvalidArgumentException("Channel driver [{$name}] not configured.");
        }

        return app($class);
    }
}
