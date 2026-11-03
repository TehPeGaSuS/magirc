<?php

declare(strict_types=1);

namespace Magirc\Anope;

/**
 * One consistent picture of the network as reported by rpc_data, already
 * filtered according to the display settings (hidden channels, U-lined
 * servers) and enriched with the derived values the pages need.
 *
 * Users, channels and servers are arrays keyed by their lower-cased name.
 * Field names follow rpc_data (see doc/RPC-MAPPING.md) with these additions:
 *   user.oper      bool, IRC operator (umode +o)
 *   user.ulined    bool, sits on a U-lined (services) server
 *   user.hidden    bool, excluded from counts and lists
 *   channel.hidden bool
 *   channel.usercount int, visible members
 *   server.usercount / server.opercount int, visible users on it
 */
final class Snapshot
{
    /**
     * @param array<string, array<string, mixed>> $users
     * @param array<string, array<string, mixed>> $channels
     * @param array<string, array<string, mixed>> $servers
     */
    public function __construct(
        public readonly int $time,
        public readonly array $users,
        public readonly array $channels,
        public readonly array $servers,
    ) {
    }

    /**
     * Users shown in lists: not hidden and, with display.registered_users_only,
     * logged in to an account.
     * @return array<string, array<string, mixed>>
     */
    public function visibleUsers(): array
    {
        return array_filter($this->users, static fn (array $u): bool => $u['listed'] ?? !$u['hidden']);
    }

    /**
     * Users that count: everyone except services clients and users on hidden
     * servers, whether or not they are listed.
     * @return array<string, array<string, mixed>>
     */
    public function countedUsers(): array
    {
        return array_filter($this->users, static fn (array $u): bool => !$u['hidden']);
    }

    /**
     * Channels shown in lists: not hidden and, with
     * display.registered_channels_only, registered with ChanServ.
     * @return array<string, array<string, mixed>>
     */
    public function visibleChannels(): array
    {
        return array_filter($this->channels, static fn (array $c): bool => $c['listed'] ?? !$c['hidden']);
    }

    /**
     * Channels that count: everything except hidden ones (secret, private or
     * configured as hidden), whether or not they are listed.
     * @return array<string, array<string, mixed>>
     */
    public function countedChannels(): array
    {
        return array_filter($this->channels, static fn (array $c): bool => !$c['hidden']);
    }

    /** @return array<string, array<string, mixed>> */
    public function visibleServers(): array
    {
        return array_filter($this->servers, static fn (array $s): bool => !$s['hidden']);
    }

    /** @return array<string, array<string, mixed>> */
    public function operators(): array
    {
        return array_filter($this->users, static fn (array $u): bool => $u['oper'] && !$u['hidden']);
    }

    /** @return array{users: int, channels: int, servers: int, operators: int} */
    public function counts(): array
    {
        return [
            'users' => count($this->countedUsers()),
            'channels' => count($this->countedChannels()),
            'servers' => count($this->visibleServers()),
            'operators' => count($this->operators()),
        ];
    }

    /** @return array<string, mixed>|null */
    public function user(string $nick): ?array
    {
        return $this->users[mb_strtolower($nick)] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function channel(string $name): ?array
    {
        return $this->channels[mb_strtolower($name)] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function server(string $name): ?array
    {
        return $this->servers[mb_strtolower($name)] ?? null;
    }

    /**
     * Visible members of a channel with their status prefixes.
     * @return list<array{nick: string, prefixes: string, user: array<string, mixed>}>
     */
    public function channelMembers(string $name): array
    {
        $channel = $this->channel($name);
        if ($channel === null) {
            return [];
        }
        $members = [];
        foreach ($channel['users'] as $entry) {
            [$prefixes, $nick] = self::splitPrefix($entry);
            $user = $this->user($nick);
            if ($user === null || !($user['listed'] ?? !$user['hidden'])) {
                continue;
            }
            $members[] = ['nick' => $nick, 'prefixes' => $prefixes, 'user' => $user];
        }
        return $members;
    }

    /**
     * Visible channels a user is in, with the user's prefixes there.
     * @return list<array{name: string, prefixes: string, channel: array<string, mixed>}>
     */
    public function userChannels(string $nick): array
    {
        $user = $this->user($nick);
        if ($user === null) {
            return [];
        }
        $result = [];
        foreach ($user['channels'] as $entry) {
            [$prefixes, $name] = self::splitPrefix($entry);
            $channel = $this->channel($name);
            if ($channel === null || !($channel['listed'] ?? !$channel['hidden'])) {
                continue;
            }
            $result[] = ['name' => $name, 'prefixes' => $prefixes, 'channel' => $channel];
        }
        return $result;
    }

    /**
     * Splits "@+nick" into ["@+", "nick"]. Channel names start with one of
     * the channel-type characters, nicks never start with a prefix symbol.
     * @return array{0: string, 1: string}
     */
    public static function splitPrefix(string $entry): array
    {
        $len = strspn($entry, '~&@%+!');
        return [substr($entry, 0, $len), substr($entry, $len)];
    }
}
