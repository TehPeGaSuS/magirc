<?php

declare(strict_types=1);

namespace Magirc\Tests;

use Magirc\Anope\Network;
use Magirc\Anope\RpcClient;
use Magirc\Anope\Snapshot;
use Magirc\Config;
use PHPUnit\Framework\TestCase;

final class NetworkTest extends TestCase
{
    /** @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>, 2: array<string, array<string, mixed>>} */
    private function fixture(): array
    {
        $servers = [
            'irc.example.org' => ['description' => 'Main', 'ulined' => false, 'sid' => '001', 'synced' => true, 'uplink' => 'services.example.org'],
            'services.example.org' => ['description' => 'Services', 'ulined' => true, 'sid' => '00B', 'synced' => true, 'uplink' => null],
        ];
        $users = [
            'Alice' => ['server' => 'irc.example.org', 'modes' => ['+xio'], 'channels' => ['@~#chat', '#secret'], 'address' => '203.0.113.5', 'account' => ['display' => 'Alice']],
            'Bob' => ['server' => 'irc.example.org', 'modes' => ['+xB'], 'channels' => ['#chat', '+#services', '#lounge'], 'address' => '203.0.113.6', 'account' => null],
            'ChanServ' => ['server' => 'services.example.org', 'modes' => ['+io'], 'channels' => ['@&#chat', '#services'], 'address' => '0.0.0.0', 'account' => null],
        ];
        $channels = [
            '#chat' => ['modes' => ['+nt', '+l 20'], 'users' => ['@~Alice', 'Bob', '@&ChanServ'], 'topic' => null, 'created' => 1, 'registered' => true],
            '#secret' => ['modes' => ['+nts'], 'users' => ['Alice'], 'topic' => null, 'created' => 1, 'registered' => false],
            '#services' => ['modes' => ['+nt'], 'users' => ['+Bob', 'ChanServ'], 'topic' => null, 'created' => 1, 'registered' => false],
            '#lounge' => ['modes' => ['+nt'], 'users' => ['Bob'], 'topic' => null, 'created' => 1, 'registered' => false],
        ];
        return [$users, $channels, $servers];
    }

    /** The fixture tests filtering rules one at a time, so the registered-only rules are off unless a test turns them on. */
    private function network(array $overrides = []): Network
    {
        $config = Config::fromArray(array_replace_recursive(
            ['display' => ['registered_channels_only' => false, 'registered_users_only' => false]],
            $overrides,
        ));
        return new Network(new RpcClient('http://127.0.0.1:1/', ''), $config);
    }

    public function testBotUserMode(): void
    {
        [$users, $channels, $servers] = $this->fixture();
        $s = $this->network()->build(100, $users, $channels, $servers);
        self::assertTrue($s->user('bob')['bot'], '+B marks a bot by default');
        self::assertFalse($s->user('alice')['bot']);

        $s = $this->network(['display' => ['bot_umode' => '']])->build(100, $users, $channels, $servers);
        self::assertFalse($s->user('bob')['bot'], 'an empty bot_umode turns it off');
    }

    public function testAwayState(): void
    {
        [$users, $channels, $servers] = $this->fixture();
        $users['Alice']['away'] = ['message' => 'lunch', 'time' => 50];
        $users['Bob']['away'] = null;
        $users['ChanServ']['away'] = null;
        $s = $this->network()->build(100, $users, $channels, $servers);
        self::assertSame(['message' => 'lunch', 'time' => 50], $s->user('alice')['away']);
        self::assertNull($s->user('bob')['away']);

        // Anope 2.1 with the inverted IsAway(): away users get null, the rest an empty record.
        $users['Alice']['away'] = null;
        $users['Bob']['away'] = ['message' => '', 'time' => 0];
        $users['ChanServ']['away'] = ['message' => '', 'time' => 0];
        $s = $this->network()->build(100, $users, $channels, $servers);
        self::assertSame(['message' => '', 'time' => 0], $s->user('alice')['away'], 'null means away when the bug is detected');
        self::assertNull($s->user('bob')['away']);
    }

    public function testRegisteredOnlyIsTheDefault(): void
    {
        [$users, $channels, $servers] = $this->fixture();
        $s = (new Network(new RpcClient('http://127.0.0.1:1/', ''), Config::fromArray([])))->build(100, $users, $channels, $servers);

        self::assertTrue($s->channel('#chat')['listed'], 'registered channel is listed');
        self::assertTrue($s->channel('#secret')['hidden'], 'secret channel is hidden outright');
        self::assertTrue($s->channel('#services')['hidden'], 'listed in display.hide_channels by default');
        self::assertFalse($s->channel('#lounge')['listed'], 'unregistered channel is not listed');
        self::assertFalse($s->channel('#lounge')['hidden'], 'but still counts');
        self::assertSame(['#chat'], array_keys($s->visibleChannels()));
        self::assertSame(['#chat'], array_column($s->userChannels('Bob'), 'name'), 'user channel list shows listed channels only');
        self::assertTrue($s->user('alice')['listed'], 'logged-in user is listed');
        self::assertFalse($s->user('bob')['listed'], 'user without account is not listed');
        self::assertFalse($s->user('bob')['hidden'], 'but still counts');
        self::assertSame(['users' => 2, 'channels' => 2, 'servers' => 1, 'operators' => 1], $s->counts(), 'counts include the unregistered channel and user');
        self::assertSame(['alice'], array_keys($s->visibleUsers()));
        self::assertSame(2, $s->channel('#chat')['usercount'], 'channel size counts unlisted users');
        self::assertSame(['Alice'], array_column($s->channelMembers('#chat'), 'nick'), 'member list shows listed users only');
        self::assertSame(2, $s->server('irc.example.org')['usercount']);
    }

    public function testFiltersUlinedServersAndTheirUsers(): void
    {
        [$users, $channels, $servers] = $this->fixture();
        $s = $this->network()->build(100, $users, $channels, $servers);

        self::assertSame(['users' => 2, 'channels' => 2, 'servers' => 1, 'operators' => 1], $s->counts());
        self::assertTrue($s->user('chanserv')['hidden']);
        self::assertTrue($s->user('chanserv')['ulined']);
        self::assertTrue($s->server('services.example.org')['hidden']);
        self::assertSame(2, $s->server('irc.example.org')['usercount']);
        self::assertSame(1, $s->server('irc.example.org')['opercount']);
    }

    public function testHidesSecretAndListedChannels(): void
    {
        [$users, $channels, $servers] = $this->fixture();
        $s = $this->network(['display' => ['hide_channels' => ['#Services']]])->build(100, $users, $channels, $servers);

        self::assertTrue($s->channel('#secret')['hidden'], 'secret channel hidden by default');
        self::assertTrue($s->channel('#services')['hidden'], 'listed channel hidden case-insensitively');
        self::assertFalse($s->channel('#chat')['hidden']);
        self::assertSame(2, $s->channel('#chat')['usercount'], 'ChanServ does not count as a member');
        self::assertSame(['#chat'], array_column($s->userChannels('Alice'), 'name'));
    }

    public function testCanShowSecretChannelsWhenConfigured(): void
    {
        [$users, $channels, $servers] = $this->fixture();
        $s = $this->network(['display' => ['hide_secret_channels' => false, 'hide_ulined' => false]])
            ->build(100, $users, $channels, $servers);

        self::assertFalse($s->channel('#secret')['hidden']);
        self::assertFalse($s->user('chanserv')['hidden']);
        self::assertSame(3, $s->counts()['users']);
        self::assertSame(2, $s->counts()['servers']);
    }

    public function testChannelMembersKeepPrefixes(): void
    {
        [$users, $channels, $servers] = $this->fixture();
        $s = $this->network()->build(100, $users, $channels, $servers);

        $members = $s->channelMembers('#CHAT');
        self::assertSame(['Alice', 'Bob'], array_column($members, 'nick'));
        self::assertSame('@~', $members[0]['prefixes']);
        self::assertSame('', $members[1]['prefixes']);
    }

    public function testHiddenServerKeepsItsUsers(): void
    {
        [$users, $channels, $servers] = $this->fixture();
        $s = $this->network(['display' => ['hide_servers' => ['IRC.example.org']]])->build(100, $users, $channels, $servers);

        self::assertTrue($s->server('irc.example.org')['hidden']);
        self::assertSame(0, $s->counts()['servers']);
        self::assertSame(2, $s->counts()['users'], 'users on a hidden server still count');
        self::assertFalse($s->user('alice')['hidden']);
        self::assertTrue($s->user('alice')['server_hidden']);
        self::assertTrue($s->user('chanserv')['hidden'], 'U-lined pseudo-clients stay hidden');
    }

    public function testModeParsing(): void
    {
        self::assertTrue(Network::hasMode(['+nt', '+l 20'], 'l'));
        self::assertFalse(Network::hasMode(['+nt', '+l 20'], '2'), 'parameters are not mode letters');
        self::assertTrue(Network::hasMode(['+xio'], 'o'));
        self::assertFalse(Network::hasMode([], 'o'));
    }

    public function testSplitPrefix(): void
    {
        self::assertSame(['@~', 'Alice'], Snapshot::splitPrefix('@~Alice'));
        self::assertSame(['', '#chat'], Snapshot::splitPrefix('#chat'));
        self::assertSame(['+', '#chat'], Snapshot::splitPrefix('+#chat'));
    }
}
