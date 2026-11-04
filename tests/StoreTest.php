<?php

declare(strict_types=1);

namespace Magirc\Tests;

use Magirc\Anope\Snapshot;
use Magirc\History\Store;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        $this->store = Store::connect('sqlite::memory:');
        $this->store->migrate();
    }

    private function snapshot(int $time, int $users, array $servers = ['irc.a' => 3]): Snapshot
    {
        $u = [];
        for ($i = 0; $i < $users; $i++) {
            $u["u$i"] = ['nick' => "u$i", 'hidden' => false, 'oper' => $i === 0, 'ulined' => false, 'channels' => [], 'modes' => []];
        }
        $s = [];
        foreach ($servers as $name => $count) {
            $s[$name] = ['name' => $name, 'hidden' => false, 'ulined' => false, 'usercount' => $count, 'opercount' => 0, 'description' => 'd'];
        }
        return new Snapshot($time, $u, ['#c' => ['name' => '#c', 'hidden' => false, 'usercount' => 1, 'users' => [], 'modes' => []]], $s);
    }

    public function testRecordsSamplesAndPeaks(): void
    {
        $this->store->record($this->snapshot(1000, 5));
        $this->store->record($this->snapshot(1300, 9));
        $this->store->record($this->snapshot(1600, 4));

        $peaks = $this->store->peaks();
        self::assertSame(['count' => 9, 'time' => 1300], $peaks['users']);
        self::assertSame(['count' => 1, 'time' => 1000], $peaks['operators']);
        self::assertSame(1600, $this->store->lastSampleTime());

        // buckets of 600s: 1000 -> 600, 1300 and 1600 -> 1200
        $history = $this->store->history(0, 600);
        self::assertCount(2, $history);
        self::assertSame(600, $history[0]['time']);
        self::assertSame(5, $history[0]['users']);
        self::assertSame(1200, $history[1]['time']);
        self::assertSame(7, $history[1]['users'], 'average of 9 and 4, rounded');
    }

    public function testSetPeakByHand(): void
    {
        $this->store->record($this->snapshot(1000, 9));
        $this->store->setPeak('users', 50, 500);
        self::assertSame(['count' => 50, 'time' => 500], $this->store->peaks()['users']);
        $this->store->record($this->snapshot(2000, 60));
        self::assertSame(['count' => 60, 'time' => 2000], $this->store->peaks()['users'], 'a higher sample still beats it');
        $this->expectException(\InvalidArgumentException::class);
        $this->store->setPeak('bogus', 1, 1);
    }

    public function testRecordingTheSameTimeTwiceIsIdempotent(): void
    {
        $this->store->record($this->snapshot(1000, 5));
        $this->store->record($this->snapshot(1000, 6));
        self::assertCount(1, $this->store->history(0, 1));
        self::assertSame(6, $this->store->history(0, 1)[0]['users']);
    }

    public function testServerLinkTracking(): void
    {
        $this->store->record($this->snapshot(1000, 1, ['irc.a' => 1, 'irc.b' => 1]));
        $this->store->record($this->snapshot(1300, 1, ['irc.a' => 1]));               // irc.b split
        $this->store->record($this->snapshot(1600, 1, ['irc.a' => 1, 'irc.b' => 2])); // irc.b back

        $seen = $this->store->serversSeen();
        self::assertSame(1000, $seen['irc.a']['linked_since']);
        self::assertSame(1600, $seen['irc.b']['linked_since'], 'relinked server restarts its linked_since');
        self::assertSame(1000, $seen['irc.b']['first_seen']);
        self::assertSame([['time' => 1000, 'users' => 1], ['time' => 1600, 'users' => 2]], $this->store->serverHistory('irc.b', 0, 1));
    }

    public function testPruneKeepsPeaks(): void
    {
        $old = time() - 10 * 86400;
        $this->store->record($this->snapshot($old, 50));
        $this->store->record($this->snapshot(time(), 2));
        self::assertSame(1, $this->store->prune(5));
        self::assertCount(1, $this->store->history(0, 1));
        self::assertSame(50, $this->store->peaks()['users']['count']);
    }
}
