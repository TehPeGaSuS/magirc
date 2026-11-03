<?php

declare(strict_types=1);

namespace Magirc\Stats;

use PDO;

/**
 * Read-only access to Anope's chanstats table.
 *
 * Row kinds in <prefix>chanstats (see doc/RPC-MAPPING.md):
 *   chan='#x', nick=''      channel totals
 *   chan='#x', nick='Acc'   an account's activity in that channel
 *   chan='',   nick='Acc'   an account's activity across all channels
 * each repeated for type in total/monthly/weekly/daily. The three period
 * rows are only meaningful when the MySQL event scheduler runs Anope's
 * cleanup events; periods() tells which types are trustworthy.
 */
final class ChanStats
{
    public const TYPES = ['daily', 'weekly', 'monthly', 'total'];

    private const COLUMNS = 'letters, words, line AS `lines`, actions, '
        . 'smileys_happy, smileys_sad, smileys_other, '
        . '(smileys_happy + smileys_sad + smileys_other) AS smileys, '
        . 'kicks, kicked, modes, topics';

    private const HOURS = 'time0, time1, time2, time3, time4, time5, time6, time7, time8, time9, time10, time11, '
        . 'time12, time13, time14, time15, time16, time17, time18, time19, time20, time21, time22, time23';

    /** @var array<string, mixed>|null */
    private ?array $status = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix = 'anope_',
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public static function connect(string $dsn, ?string $username, ?string $password, string $prefix): self
    {
        return new self(new PDO($dsn, $username, $password, [PDO::ATTR_TIMEOUT => 5]), $prefix);
    }

    private function table(): string
    {
        return '`' . str_replace('`', '', $this->prefix) . 'chanstats`';
    }

    public function tableExists(): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$this->prefix . 'chanstats']);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Scheduler and cleanup-event state, the basis for periods().
     *
     * @return array{scheduler: string, events: array<string, array{status: string, last_executed: ?string}>, periods: bool, reason: string}
     */
    public function status(): array
    {
        if ($this->status !== null) {
            return $this->status;
        }
        $scheduler = (string) $this->pdo->query('SELECT @@GLOBAL.event_scheduler')->fetchColumn();
        $events = [];
        $stmt = $this->pdo->query(
            "SELECT EVENT_NAME, STATUS, LAST_EXECUTED FROM information_schema.EVENTS "
            . "WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME LIKE 'chanstats\\_event\\_cleanup\\_%'"
        );
        foreach ($stmt->fetchAll() as $row) {
            $events[(string) $row['EVENT_NAME']] = [
                'status' => (string) $row['STATUS'],
                'last_executed' => $row['LAST_EXECUTED'] !== null ? (string) $row['LAST_EXECUTED'] : null,
            ];
        }

        $reason = '';
        if (strcasecmp($scheduler, 'ON') !== 0) {
            $reason = "The MySQL event scheduler is $scheduler; Anope's daily/weekly/monthly reset events never run.";
        } elseif (count($events) < 3) {
            $reason = 'The chanstats cleanup events are missing; has Anope\'s chanstats module run against this database?';
        } else {
            foreach ($events as $name => $e) {
                if (strcasecmp($e['status'], 'ENABLED') !== 0) {
                    $reason = "Event $name is {$e['status']}.";
                    break;
                }
            }
        }

        return $this->status = [
            'scheduler' => $scheduler,
            'events' => $events,
            'periods' => $reason === '',
            'reason' => $reason,
        ];
    }

    /**
     * The period types whose rows mean what their name says.
     * @return list<string>
     */
    public function periods(): array
    {
        return $this->status()['periods'] ? self::TYPES : ['total'];
    }

    /** Falls back to 'total' for an unknown or unavailable type. */
    public function normalizeType(?string $type): string
    {
        $type = strtolower((string) $type);
        return in_array($type, $this->periods(), true) ? $type : 'total';
    }

    public function channelHasStats(string $channel): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table()} WHERE chan = ? AND nick = '' AND type = 'total'");
        $stmt->execute([$channel]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function userHasStats(string $account): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table()} WHERE chan = '' AND nick = ? AND type = 'total'");
        $stmt->execute([$account]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Channels ordered by lines, channel totals only.
     * @param list<string> $exclude lower-cased channel names to leave out
     * @return list<array<string, mixed>>
     */
    public function topChannels(string $type, int $limit = 10, array $exclude = []): array
    {
        $type = $this->normalizeType($type);
        $sql = "SELECT chan AS channel, " . self::COLUMNS . " FROM {$this->table()} "
            . "WHERE nick = '' AND chan <> '' AND type = ? AND line > 0";
        $params = [$type];
        if ($exclude !== []) {
            $sql .= ' AND LOWER(chan) NOT IN (' . implode(',', array_fill(0, count($exclude), '?')) . ')';
            $params = array_merge($params, $exclude);
        }
        $sql .= ' ORDER BY line DESC LIMIT ' . max(1, $limit);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $this->ints($stmt->fetchAll());
    }

    /**
     * Accounts ordered by lines across all channels.
     * @return list<array<string, mixed>>
     */
    public function topUsers(string $type, int $limit = 10): array
    {
        $type = $this->normalizeType($type);
        $stmt = $this->pdo->prepare(
            "SELECT nick AS account, " . self::COLUMNS . " FROM {$this->table()} "
            . "WHERE chan = '' AND nick <> '' AND type = ? AND line > 0 ORDER BY line DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute([$type]);
        return $this->ints($stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function channelTotals(string $channel, string $type): ?array
    {
        return $this->one("chan = ? AND nick = ''", [$channel], $type);
    }

    /**
     * Per-account rows for one channel, ordered by lines.
     * @return list<array<string, mixed>>
     */
    public function channelUsers(string $channel, string $type, int $limit = 50): array
    {
        $type = $this->normalizeType($type);
        $stmt = $this->pdo->prepare(
            "SELECT nick AS account, " . self::COLUMNS . " FROM {$this->table()} "
            . "WHERE chan = ? AND nick <> '' AND type = ? AND line > 0 ORDER BY line DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute([$channel, $type]);
        return $this->ints($stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function userTotals(string $account, string $type): ?array
    {
        return $this->one("chan = '' AND nick = ?", [$account], $type);
    }

    /**
     * Channels one account is active in, ordered by lines.
     * @return list<array<string, mixed>>
     */
    public function userChannels(string $account, string $type, int $limit = 50): array
    {
        $type = $this->normalizeType($type);
        $stmt = $this->pdo->prepare(
            "SELECT chan AS channel, " . self::COLUMNS . " FROM {$this->table()} "
            . "WHERE chan <> '' AND nick = ? AND type = ? AND line > 0 ORDER BY line DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute([$account, $type]);
        return $this->ints($stmt->fetchAll());
    }

    /**
     * Lines per hour of day (0..23) for a channel, an account, or an account
     * in a channel. Empty string selects "all" on either side.
     * @return list<int>
     */
    public function hourly(string $channel, string $account, string $type): array
    {
        $type = $this->normalizeType($type);
        $stmt = $this->pdo->prepare("SELECT " . self::HOURS . " FROM {$this->table()} WHERE chan = ? AND nick = ? AND type = ?");
        $stmt->execute([$channel, $account, $type]);
        $row = $stmt->fetch();
        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[] = $row ? (int) $row["time$h"] : 0;
        }
        return $hours;
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function one(string $where, array $params, string $type): ?array
    {
        $type = $this->normalizeType($type);
        $stmt = $this->pdo->prepare("SELECT " . self::COLUMNS . " FROM {$this->table()} WHERE $where AND type = ?");
        $stmt->execute([...$params, $type]);
        $row = $stmt->fetch();
        return $row ? $this->ints([$row])[0] : null;
    }

    /**
     * PDO/MySQL returns numbers as strings; the templates and JSON want ints.
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function ints(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach ($row as $k => $v) {
                if ($k !== 'channel' && $k !== 'account' && is_numeric($v)) {
                    $row[$k] = (int) $v;
                }
            }
        }
        return array_values($rows);
    }
}
