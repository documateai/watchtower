<?php

namespace Documateai\Watchtower\Services\CommandBus;

use Illuminate\Support\Facades\DB;
use Documateai\Watchtower\Contracts\CommandBusInterface;

class DatabaseCommandBus implements CommandBusInterface
{
    public function __construct(
        protected ?string $connection = null,
    ) {}

    public function put(string $key, string $value, int $ttl = 300): void
    {
        try {
            $expiresAt = now()->addSeconds($ttl);

            $this->table()->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'expires_at' => $expiresAt, 'created_at' => now()],
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function get(string $key): ?string
    {
        try {
            // Clean expired rows inline
            $this->table()->where('expires_at', '<', now())->delete();

            $row = $this->table()
                ->where('key', $key)
                ->where('expires_at', '>', now())
                ->first();

            return $row?->value;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function forget(string $key): void
    {
        try {
            $this->table()->where('key', $key)->delete();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function table(): \Illuminate\Database\Query\Builder
    {
        return DB::connection($this->connection)->table('watchtower_commands');
    }
}
