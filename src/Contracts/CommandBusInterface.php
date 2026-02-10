<?php

namespace Documateai\Watchtower\Contracts;

interface CommandBusInterface
{
    public function put(string $key, string $value, int $ttl = 300): void;

    public function get(string $key): ?string;

    public function forget(string $key): void;
}
