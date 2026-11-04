<?php

declare(strict_types=1);

namespace Magirc\Anope;

use Magirc\Config;

/**
 * Fetches the live network state from rpc_data and turns it into a Snapshot.
 *
 * Three calls (listUsers, listChannels, listServers with "full" detail) give
 * everything. The result is cached on disk for anope.cache_ttl seconds so a
 * busy page does not hit services on every request; the sampler and the
 * check command bypass the cache.
 */
final class Network
{
    private ?Snapshot $snapshot = null;

    public function __construct(
        private readonly RpcClient $rpc,
        private readonly Config $config,
        private readonly ?string $cacheFile = null,
    ) {
    }

    public function snapshot(bool $fresh = false): Snapshot
    {
        if ($this->snapshot !== null && !$fresh) {
            return $this->snapshot;
        }
        if (!$fresh) {
            $cached = $this->readCache();
            if ($cached !== null) {
                return $this->snapshot = $cached;
            }
        }
        $this->snapshot = $this->fetch();
        $this->writeCache($this->snapshot);
        return $this->snapshot;
    }

    public function fetch(): Snapshot
    {
        $servers = $this->rpc->call('anope.listServers', ['full']);
        $channels = $this->rpc->call('anope.listChannels', ['full']);
        $users = $this->rpc->call('anope.listUsers', ['full']);
        if (!is_array($servers) || !is_array($channels) || !is_array($users)) {
            throw new RpcException('rpc_data returned no data; is the rpc_data module loaded?');
        }
        return $this->build(time(), $users, $channels, $servers);
    }

    /**
     * @param array<string, array<string, mixed>> $users
     * @param array<string, array<string, mixed>> $channels
     * @param array<string, array<string, mixed>> $servers
     */
    public function build(int $time, array $users, array $channels, array $servers): Snapshot
    {
        $hideUlined = $this->config->bool('display.hide_ulined', true);
        $registeredChannels = $this->config->bool('display.registered_channels_only', true);
        $registeredUsers = $this->config->bool('display.registered_users_only', true);
        $hideSecret = $this->config->bool('display.hide_secret_channels', true);
        $hidePrivate = $this->config->bool('display.hide_private_channels', true);
        $hiddenChannels = array_map('mb_strtolower', $this->config->list('display.hide_channels'));
        $hiddenServers = array_map('mb_strtolower', $this->config->list('display.hide_servers'));

        $srv = [];
        foreach ($servers as $name => $s) {
            $key = mb_strtolower((string) $name);
            $s['name'] = (string) $name;
            $s['ulined'] = (bool) ($s['ulined'] ?? false);
            $s['hidden'] = in_array($key, $hiddenServers, true) || ($hideUlined && $s['ulined']);
            $s['usercount'] = 0;
            $s['opercount'] = 0;
            $srv[$key] = $s;
        }

        $usr = [];
        foreach ($users as $nick => $u) {
            $key = mb_strtolower((string) $nick);
            $u['nick'] = (string) $nick;
            $serverKey = mb_strtolower((string) ($u['server'] ?? ''));
            $u['ulined'] = $srv[$serverKey]['ulined'] ?? false;
            $u['oper'] = self::hasMode($u['modes'] ?? [], 'o');
            $u['registered'] = is_array($u['account'] ?? null) && ($u['account']['display'] ?? '') !== '';
            // hidden: never counted nor listed (services clients, hidden servers).
            // listed: appears in user lists; counts include unlisted users too.
            // Users on U-lined servers are services pseudo-clients, not people.
            // A server in display.hide_servers hides the server only; its
            // users still count and are listed (without a link to it).
            $u['hidden'] = $u['ulined'] && $hideUlined;
            $u['listed'] = !$u['hidden'] && (!$registeredUsers || $u['registered']);
            $u['server_hidden'] = $srv[$serverKey]['hidden'] ?? false;
            $u['channels'] = is_array($u['channels'] ?? null) ? $u['channels'] : [];
            $u['modes'] = is_array($u['modes'] ?? null) ? $u['modes'] : [];
            $usr[$key] = $u;
            if (!$u['hidden'] && isset($srv[$serverKey])) {
                $srv[$serverKey]['usercount']++;
                if ($u['oper']) {
                    $srv[$serverKey]['opercount']++;
                }
            }
        }

        $chn = [];
        foreach ($channels as $name => $c) {
            $key = mb_strtolower((string) $name);
            $c['name'] = (string) $name;
            $c['modes'] = is_array($c['modes'] ?? null) ? $c['modes'] : [];
            $c['users'] = is_array($c['users'] ?? null) ? $c['users'] : [];
            $c['registered'] = (bool) ($c['registered'] ?? false);
            // hidden: never counted nor listed. listed: shown in channel lists;
            // counts include unlisted (unregistered) channels too.
            $c['hidden'] = in_array($key, $hiddenChannels, true)
                || ($hideSecret && self::hasMode($c['modes'], 's'))
                || ($hidePrivate && self::hasMode($c['modes'], 'p'));
            $count = 0;
            foreach ($c['users'] as $entry) {
                [, $memberNick] = Snapshot::splitPrefix((string) $entry);
                $member = $usr[mb_strtolower($memberNick)] ?? null;
                if ($member !== null && !$member['hidden']) {
                    $count++;
                }
            }
            $c['usercount'] = $count;
            $c['listed'] = !$c['hidden'] && (!$registeredChannels || $c['registered']);
            $chn[$key] = $c;
        }

        return new Snapshot($time, $usr, $chn, $srv);
    }

    /**
     * rpc_data reports modes as strings like "+xiw" or "+l 20"; a mode is
     * set when its letter appears in the first word of any entry.
     * @param list<mixed> $modes
     */
    public static function hasMode(array $modes, string $letter): bool
    {
        foreach ($modes as $entry) {
            $word = strtok((string) $entry, ' ');
            if ($word !== false && str_contains(ltrim($word, '+'), $letter)) {
                return true;
            }
        }
        return false;
    }

    private function readCache(): ?Snapshot
    {
        if ($this->cacheFile === null || !is_file($this->cacheFile)) {
            return null;
        }
        $ttl = $this->config->int('anope.cache_ttl', 15);
        if (filemtime($this->cacheFile) + $ttl < time()) {
            return null;
        }
        $raw = @file_get_contents($this->cacheFile);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['time'], $data['users'], $data['channels'], $data['servers'])) {
            return null;
        }
        return new Snapshot((int) $data['time'], $data['users'], $data['channels'], $data['servers']);
    }

    private function writeCache(Snapshot $snapshot): void
    {
        if ($this->cacheFile === null) {
            return;
        }
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return;
        }
        $tmp = $this->cacheFile . '.' . getmypid();
        $json = json_encode([
            'time' => $snapshot->time,
            'users' => $snapshot->users,
            'channels' => $snapshot->channels,
            'servers' => $snapshot->servers,
        ]);
        if ($json !== false && @file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $this->cacheFile);
        }
    }
}
