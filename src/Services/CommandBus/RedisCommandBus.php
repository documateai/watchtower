<?php

namespace Documateai\Watchtower\Services\CommandBus;

use Illuminate\Support\Facades\Redis;
use Documateai\Watchtower\Contracts\CommandBusInterface;

class RedisCommandBus implements CommandBusInterface
{
    public function __construct(
        protected string $connection = 'default',
    ) {}

    public function put(string $key, string $value, int $ttl = 300): void
    {
        try {
            $redis = Redis::connection($this->connection);
            $redis->set($key, $value);
            $redis->expire($key, $ttl);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function get(string $key): ?string
    {
        try {
            return Redis::connection($this->connection)->get($key);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function forget(string $key): void
    {
        try {
            Redis::connection($this->connection)->del($key);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
