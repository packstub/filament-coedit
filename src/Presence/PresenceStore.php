<?php

namespace Packstub\CoEdit\Presence;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Who is on which record, kept in the cache.
 *
 * A room is one record. Each open tab is a member of its room until it stops
 * sending heartbeats; members are grouped per user when they are read, so a
 * person with two tabs shows up once.
 */
class PresenceStore
{
    /** Avatar ring colours, picked per user so a person keeps theirs across records. */
    public const COLORS = ['#e11d48', '#2563eb', '#059669', '#d97706', '#7c3aed', '#0891b2', '#c026d3', '#65a30d'];

    /** @var (Closure(Model): string)|null */
    protected static ?Closure $roomResolver = null;

    /**
     * The room of a record: its database, type and key. The database is part
     * of it so the same id in two tenant databases are two different records.
     */
    public static function room(Model $record): string
    {
        if (static::$roomResolver) {
            return (string) (static::$roomResolver)($record);
        }

        return $record->getConnection()->getDatabaseName().'|'.$record->getMorphClass().':'.$record->getKey();
    }

    /**
     * @param  (Closure(Model): string)|null  $resolver
     */
    public static function resolveRoomUsing(?Closure $resolver): void
    {
        static::$roomResolver = $resolver;
    }

    public static function colorFor(string $user): string
    {
        return self::COLORS[crc32($user) % count(self::COLORS)];
    }

    /**
     * @param  array{user: string, name: string, avatar: ?string}  $who
     */
    public function touch(string $room, string $tab, array $who, string $mode, ?string $field = null): void
    {
        $this->mutate($room, function (array $members) use ($tab, $who, $mode, $field): array {
            $members[$tab] = [
                'user' => $who['user'],
                'name' => $who['name'],
                'avatar' => $who['avatar'],
                'mode' => $mode,
                'field' => $field,
                'seen' => $this->now(),
            ];

            return $members;
        });
    }

    public function leave(string $room, string $tab): void
    {
        $this->mutate($room, function (array $members) use ($tab): array {
            unset($members[$tab]);

            return $members;
        });
    }

    /**
     * Everyone on the record, one entry per user: the most active mode across
     * their tabs and every field they are in.
     *
     * @return list<array{user: string, name: string, avatar: ?string, color: string, mode: string, fields: list<string>}>
     */
    public function members(string $room, ?string $exceptUser = null): array
    {
        return $this->group($this->alive($this->cache()->get($this->key($room), [])), $exceptUser);
    }

    /**
     * @param  list<string>  $rooms
     * @return array<string, list<array{user: string, name: string, avatar: ?string, color: string, mode: string, fields: list<string>}>>
     */
    public function membersOfMany(array $rooms, ?string $exceptUser = null): array
    {
        if ($rooms === []) {
            return [];
        }

        $keys = array_combine($rooms, array_map($this->key(...), $rooms));
        $values = $this->cache()->many(array_values($keys));

        $result = [];

        foreach ($keys as $room => $key) {
            $result[$room] = $this->group($this->alive($values[$key] ?? []), $exceptUser);
        }

        return $result;
    }

    /**
     * Remember a save so the others on the record can be told who saved and what.
     *
     * @param  array{user: string, name: string, avatar: ?string}  $who
     * @param  list<string>  $fields  labels of the fields that changed
     * @return array{version: int, user: string, name: string, at: int, fields: list<string>}
     */
    public function recordSave(string $room, array $who, array $fields): array
    {
        $key = $this->key($room).':save';

        return $this->locked($key, function () use ($key, $who, $fields): array {
            $save = [
                'version' => (int) ($this->cache()->get($key)['version'] ?? 0) + 1,
                'user' => $who['user'],
                'name' => $who['name'],
                'at' => $this->now(),
                'fields' => array_values($fields),
            ];

            $this->cache()->put($key, $save, (int) config('filament-coedit.remember_saves_for', 3600));

            return $save;
        });
    }

    /**
     * @return array{version: int, user: string, name: string, at: int, fields: list<string>}|null
     */
    public function lastSave(string $room): ?array
    {
        return $this->cache()->get($this->key($room).':save');
    }

    /**
     * @param  callable(array<string, array<string, mixed>>): array<string, array<string, mixed>>  $change
     */
    protected function mutate(string $room, callable $change): void
    {
        $key = $this->key($room);

        $this->locked($key, function () use ($key, $change): void {
            $members = $this->alive($change($this->alive($this->cache()->get($key, []))));

            $members === []
                ? $this->cache()->forget($key)
                : $this->cache()->put($key, $members, $this->timeout() * 3);
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function locked(string $key, callable $callback): mixed
    {
        $store = $this->cache()->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        return $store->lock($key.':lock', 5)->block(3, $callback);
    }

    /**
     * @param  array<string, array<string, mixed>>  $members
     * @return array<string, array<string, mixed>>
     */
    protected function alive(array $members): array
    {
        $cutoff = $this->now() - $this->timeout();

        return array_filter($members, fn (array $member): bool => $member['seen'] >= $cutoff);
    }

    /**
     * @param  array<string, array<string, mixed>>  $members
     * @return list<array{user: string, name: string, avatar: ?string, color: string, mode: string, fields: list<string>}>
     */
    protected function group(array $members, ?string $exceptUser): array
    {
        $users = [];

        foreach ($members as $member) {
            if ($exceptUser !== null && $member['user'] === $exceptUser) {
                continue;
            }

            $user = $users[$member['user']] ??= [
                'user' => $member['user'],
                'name' => $member['name'],
                'avatar' => $member['avatar'],
                'color' => self::colorFor($member['user']),
                'mode' => $member['mode'],
                'fields' => [],
                'seen' => $member['seen'],
            ];

            if ($member['mode'] === 'editing') {
                $user['mode'] = 'editing';
            }

            if (filled($member['field']) && ! in_array($member['field'], $user['fields'], true)) {
                $user['fields'][] = $member['field'];
            }

            $user['seen'] = min($user['seen'], $member['seen']);
            $users[$member['user']] = $user;
        }

        // Whoever arrived first stays first, so avatars do not reshuffle on every heartbeat.
        usort($users, fn (array $a, array $b): int => [$a['seen'], $a['user']] <=> [$b['seen'], $b['user']]);

        return array_map(function (array $user): array {
            unset($user['seen']);

            return $user;
        }, $users);
    }

    protected function key(string $room): string
    {
        return 'filament-coedit:'.sha1($room);
    }

    protected function timeout(): int
    {
        return max(1, (int) config('filament-coedit.timeout', 20));
    }

    protected function now(): int
    {
        return now()->getTimestamp();
    }

    protected function cache(): Repository
    {
        return Cache::store(config('filament-coedit.cache_store'));
    }
}
