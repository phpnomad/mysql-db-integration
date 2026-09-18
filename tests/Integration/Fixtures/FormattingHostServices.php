<?php

namespace PHPNomad\MySql\Integration\Tests\Integration\Fixtures;

use PHPNomad\Cache\Exceptions\CachedItemNotFoundException;
use PHPNomad\Cache\Interfaces\CachePolicy;
use PHPNomad\Cache\Interfaces\CacheStrategy;
use PHPNomad\Events\Interfaces\Event;
use PHPNomad\Events\Interfaces\EventStrategy;

/** In-memory host services for the library's production initializer. */
final class FormattingHostServices implements CachePolicy, CacheStrategy, EventStrategy
{
    /** @var array<string, array{value: mixed, expires: ?int}> */
    private array $cache = [];
    /** @var array<string, array<int, list<callable>>> */
    private array $listeners = [];

    public function get(string $key): mixed
    {
        if (!$this->exists($key)) {
            throw new CachedItemNotFoundException();
        }
        return $this->cache[$key]['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl): void
    {
        $this->cache[$key] = ['value' => $value, 'expires' => $ttl === null ? null : time() + $ttl];
    }

    public function delete(string $key): void { unset($this->cache[$key]); }
    public function clear(): void { $this->cache = []; }
    public function exists(string $key): bool
    {
        if (!array_key_exists($key, $this->cache)) {
            return false;
        }
        $expiry = $this->cache[$key]['expires'];
        return $expiry === null || $expiry > time();
    }

    /** @param array<array-key, mixed> $context */
    public function getCacheKey(array $context): string { return json_encode($context, JSON_THROW_ON_ERROR); }
    /** @param array<array-key, mixed> $context */
    public function shouldCache(string $operation, array $context = []): bool { return true; }
    /** @param array<array-key, mixed> $context */
    public function getTtl(array $context = []): ?int { return null; }
    /** @param array<array-key, mixed> $context */
    public function shouldInvalidate(string $operation, array $context = []): bool { return true; }

    public function attach(string $event, callable $action, ?int $priority = null): void
    {
        $this->listeners[$event][$priority ?? 10][] = $action;
    }

    public function detach(string $event, callable $action, ?int $priority = null): void
    {
        $rank = $priority ?? 10;
        $this->listeners[$event][$rank] = array_values(array_filter(
            $this->listeners[$event][$rank] ?? [], static fn(callable $listener): bool => $listener !== $action
        ));
    }

    public function broadcast(Event $event): void
    {
        $listeners = $this->listeners[$event::getId()] ?? [];
        ksort($listeners);
        foreach ($listeners as $actions) {
            foreach ($actions as $action) {
                $action($event);
            }
        }
    }
}
