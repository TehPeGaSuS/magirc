<?php

declare(strict_types=1);

namespace Magirc\History;

use Magirc\Anope\Snapshot;
use PDO;

/**
 * MagIRC's own record of how the network evolves: periodic samples of the
 * user/channel/server/operator counts (plus users per server) and the
 * all-time peaks. Replaces the MySQL events MagIRC 1.x needed.
 *
 * Plain SQL that works on SQLite (the default) and MySQL/MariaDB.
 */
final class Store
{
    public const PEAK_TYPES = ['users', 'channels', 'servers', 'operators'];

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public static function connect(string $dsn, ?string $username = null, ?string $password = null): self
    {
        if (str_starts_with($dsn, 'sqlite:')) {
            $dir = dirname(substr($dsn, 7));
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
        $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_TIMEOUT => 5]);
        if (str_starts_with($dsn, 'sqlite:')) {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
        return new self($pdo);
    }

    public function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS magirc_samples ('
            . 'time INTEGER NOT NULL PRIMARY KEY, '
            . 'users INTEGER NOT NULL, channels INTEGER NOT NULL, '
            . 'servers INTEGER NOT NULL, operators INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS magirc_server_samples ('
            . 'time INTEGER NOT NULL, server VARCHAR(64) NOT NULL, users INTEGER NOT NULL, '
            . 'PRIMARY KEY (time, server))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS magirc_peaks ('
            . 'type VARCHAR(16) NOT NULL PRIMARY KEY, count INTEGER NOT NULL, time INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS magirc_servers_seen ('
            . 'server VARCHAR(64) NOT NULL PRIMARY KEY, first_seen INTEGER NOT NULL, '
            . 'last_seen INTEGER NOT NULL, linked_since INTEGER NOT NULL, description VARCHAR(255) NOT NULL DEFAULT \'\')');
    }

    /**
     * Records one snapshot: counts, per-server users, peaks and the server
     * link bookkeeping. Idempotent for the same timestamp.
     */
    public function record(Snapshot $snapshot): void
    {
        $counts = $snapshot->counts();
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('DELETE FROM magirc_samples WHERE time = ?');
            $stmt->execute([$snapshot->time]);
            $stmt = $this->pdo->prepare('INSERT INTO magirc_samples (time, users, channels, servers, operators) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$snapshot->time, $counts['users'], $counts['channels'], $counts['servers'], $counts['operators']]);

            $del = $this->pdo->prepare('DELETE FROM magirc_server_samples WHERE time = ?');
            $del->execute([$snapshot->time]);
            $ins = $this->pdo->prepare('INSERT INTO magirc_server_samples (time, server, users) VALUES (?, ?, ?)');
            $seen = $this->pdo->prepare('SELECT server, last_seen, linked_since FROM magirc_servers_seen WHERE server = ?');
            $insSeen = $this->pdo->prepare('INSERT INTO magirc_servers_seen (server, first_seen, last_seen, linked_since, description) VALUES (?, ?, ?, ?, ?)');
            $updSeen = $this->pdo->prepare('UPDATE magirc_servers_seen SET last_seen = ?, linked_since = ?, description = ? WHERE server = ?');
            $previousTime = $this->previousSampleTime($snapshot->time);
            foreach ($snapshot->visibleServers() as $server) {
                $ins->execute([$snapshot->time, $server['name'], $server['usercount']]);
                $seen->execute([$server['name']]);
                $row = $seen->fetch();
                $desc = (string) ($server['description'] ?? '');
                if ($row === false) {
                    $insSeen->execute([$server['name'], $snapshot->time, $snapshot->time, $snapshot->time, $desc]);
                } else {
                    // A gap since the previous sample means the server was unlinked in between.
                    $linkedSince = ((int) $row['last_seen'] === $previousTime) ? (int) $row['linked_since'] : $snapshot->time;
                    $updSeen->execute([$snapshot->time, $linkedSince, $desc, $server['name']]);
                }
            }

            foreach (self::PEAK_TYPES as $type) {
                $this->updatePeak($type, $counts[$type], $snapshot->time);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string, array{count: int, time: int}> */
    public function peaks(): array
    {
        $peaks = [];
        foreach (self::PEAK_TYPES as $type) {
            $peaks[$type] = ['count' => 0, 'time' => 0];
        }
        foreach ($this->pdo->query('SELECT type, count, time FROM magirc_peaks')->fetchAll() as $row) {
            $peaks[(string) $row['type']] = ['count' => (int) $row['count'], 'time' => (int) $row['time']];
        }
        return $peaks;
    }

    /**
     * Samples since $since, averaged into buckets of $bucket seconds so a
     * year of 5-minute samples stays a few hundred points.
     * @return list<array{time: int, users: int, channels: int, servers: int, operators: int}>
     */
    public function history(int $since, int $bucket = 3600): array
    {
        $bucket = max(1, $bucket);
        $stmt = $this->pdo->prepare(
            'SELECT (time - (time % ?)) AS t, ROUND(AVG(users)) AS users, ROUND(AVG(channels)) AS channels, '
            . 'ROUND(AVG(servers)) AS servers, ROUND(AVG(operators)) AS operators '
            . 'FROM magirc_samples WHERE time >= ? GROUP BY t ORDER BY t'
        );
        $stmt->execute([$bucket, $since]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'time' => (int) $row['t'],
                'users' => (int) $row['users'],
                'channels' => (int) $row['channels'],
                'servers' => (int) $row['servers'],
                'operators' => (int) $row['operators'],
            ];
        }
        return $out;
    }

    /**
     * Users on one server over time.
     * @return list<array{time: int, users: int}>
     */
    public function serverHistory(string $server, int $since, int $bucket = 3600): array
    {
        $bucket = max(1, $bucket);
        $stmt = $this->pdo->prepare(
            'SELECT (time - (time % ?)) AS t, ROUND(AVG(users)) AS users FROM magirc_server_samples '
            . 'WHERE server = ? AND time >= ? GROUP BY t ORDER BY t'
        );
        $stmt->execute([$bucket, $server, $since]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = ['time' => (int) $row['t'], 'users' => (int) $row['users']];
        }
        return $out;
    }

    /**
     * Every server ever sampled, with when it was first/last seen and since
     * when it has been linked without a gap. Gives the "offline servers" and
     * "linked since" information irc2sql used to provide.
     * @return array<string, array{first_seen: int, last_seen: int, linked_since: int, description: string}>
     */
    public function serversSeen(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT server, first_seen, last_seen, linked_since, description FROM magirc_servers_seen ORDER BY server')->fetchAll() as $row) {
            $out[(string) $row['server']] = [
                'first_seen' => (int) $row['first_seen'],
                'last_seen' => (int) $row['last_seen'],
                'linked_since' => (int) $row['linked_since'],
                'description' => (string) $row['description'],
            ];
        }
        return $out;
    }

    public function lastSampleTime(): ?int
    {
        $v = $this->pdo->query('SELECT MAX(time) FROM magirc_samples')->fetchColumn();
        return $v === null || $v === false ? null : (int) $v;
    }

    /** Deletes samples older than $days days (peaks are kept). Returns rows removed. */
    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = time() - $days * 86400;
        $stmt = $this->pdo->prepare('DELETE FROM magirc_server_samples WHERE time < ?');
        $stmt->execute([$cutoff]);
        $stmt = $this->pdo->prepare('DELETE FROM magirc_samples WHERE time < ?');
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    private function previousSampleTime(int $before): int
    {
        $stmt = $this->pdo->prepare('SELECT MAX(time) FROM magirc_samples WHERE time < ?');
        $stmt->execute([$before]);
        $v = $stmt->fetchColumn();
        return $v === null || $v === false ? 0 : (int) $v;
    }

    /**
     * Sets a peak by hand, e.g. Anope's "Maximum users" from before MagIRC
     * was installed (bin/magirc peak). Overwrites whatever is recorded.
     */
    public function setPeak(string $type, int $count, int $time): void
    {
        if (!in_array($type, self::PEAK_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown peak type: $type");
        }
        $this->pdo->prepare('DELETE FROM magirc_peaks WHERE type = ?')->execute([$type]);
        $this->pdo->prepare('INSERT INTO magirc_peaks (type, count, time) VALUES (?, ?, ?)')->execute([$type, $count, $time]);
    }

    private function updatePeak(string $type, int $count, int $time): void
    {
        $stmt = $this->pdo->prepare('SELECT count FROM magirc_peaks WHERE type = ?');
        $stmt->execute([$type]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            $this->pdo->prepare('INSERT INTO magirc_peaks (type, count, time) VALUES (?, ?, ?)')->execute([$type, $count, $time]);
        } elseif ($count > (int) $current) {
            $this->pdo->prepare('UPDATE magirc_peaks SET count = ?, time = ? WHERE type = ?')->execute([$count, $time, $type]);
        }
    }
}
