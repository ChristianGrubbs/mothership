<?php

namespace Mothership\DAM\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Mothership\User\Models\Admin;
use Throwable;

final class HomePresence
{
    private const PRESENCE_KEY = 'mothership:home:presence:v1';

    private const THROTTLE_KEY_PREFIX = 'mothership:home:presence:throttle:v1:';

    private const THROTTLE_SECONDS = 30;

    private const WINDOW_SECONDS = 300;

    private const PRESENCE_TTL_SECONDS = 330;

    /** Accepts at most one write per admin every thirty seconds; later calls inside that window are silently dropped. */
    public function record(Admin $admin, CarbonImmutable $now): void
    {
        $connection = Redis::connection(config('dam-home.presence_connection'));

        // Predis forwards SET's NX/EX modifiers as variadic arguments; Larastan only sees the
        // phpredis \Redis::set() signature through the connection's mixin.
        // @phpstan-ignore arguments.count
        $accepted = $connection->set(
            self::THROTTLE_KEY_PREFIX.$admin->id,
            (string) $now->getTimestamp(),
            'NX',
            'EX',
            self::THROTTLE_SECONDS,
        );

        if ($accepted === null) {
            return;
        }

        $connection->zadd(self::PRESENCE_KEY, $now->getTimestamp(), (string) $admin->id);
        $connection->expire(self::PRESENCE_KEY, self::PRESENCE_TTL_SECONDS);
    }

    /**
     * @return array{available: bool, online_count: int, users: list<array{id:int,name:string,avatar_url:?string,is_current:bool}>}
     */
    public function activeUsers(Admin $viewer, CarbonImmutable $now): array
    {
        try {
            $connection = Redis::connection(config('dam-home.presence_connection'));

            $connection->zremrangebyscore(self::PRESENCE_KEY, '-inf', $now->getTimestamp() - self::WINDOW_SECONDS);

            $scores = $connection->zrevrangebyscore(self::PRESENCE_KEY, '+inf', '-inf', 'withscores');
        } catch (Throwable) {
            return ['available' => false, 'online_count' => 0, 'users' => []];
        }

        $ids = array_map('intval', array_keys($scores));

        if ($ids === []) {
            return ['available' => true, 'online_count' => 0, 'users' => []];
        }

        $admins = Admin::humans()->where('status', 1)->whereIn('id', $ids)->get()->keyBy('id');

        $rows = [];

        foreach ($ids as $id) {
            $admin = $admins->get($id);

            if (! $admin) {
                continue;
            }

            $rows[] = [
                'id'         => $admin->id,
                'name'       => $admin->name,
                'avatar_url' => $admin->avatar_url,
                'is_current' => $admin->id === $viewer->id,
                'score'      => (float) $scores[$id],
            ];
        }

        usort($rows, function (array $a, array $b) use ($viewer): int {
            if ($a['id'] === $viewer->id) {
                return -1;
            }

            if ($b['id'] === $viewer->id) {
                return 1;
            }

            return $b['score'] <=> $a['score'] ?: $a['name'] <=> $b['name'];
        });

        return [
            'available'    => true,
            'online_count' => count($rows),
            'users'        => array_map(
                static fn (array $row): array => array_intersect_key($row, array_flip(['id', 'name', 'avatar_url', 'is_current'])),
                $rows,
            ),
        ];
    }
}
