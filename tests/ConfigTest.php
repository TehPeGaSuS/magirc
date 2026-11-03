<?php

declare(strict_types=1);

namespace Magirc\Tests;

use Magirc\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultsAreMergedWithOverrides(): void
    {
        $c = Config::fromArray(['anope' => ['token' => 'abc'], 'display' => ['hide_channels' => ['#x']]]);

        self::assertSame('abc', $c->string('anope.token'));
        self::assertSame('http://127.0.0.1:8080/jsonrpc', $c->string('anope.url'), 'untouched keys keep their default');
        self::assertSame(15, $c->int('anope.cache_ttl'));
        self::assertSame(['#x'], $c->list('display.hide_channels'), 'lists are replaced, not merged');
        self::assertTrue($c->bool('display.hide_ulined'));
        self::assertFalse($c->chanstatsEnabled());
        self::assertFalse($c->geoipEnabled());
        self::assertNull($c->get('does.not.exist'));
        self::assertSame('x', $c->string('does.not.exist', 'x'));
    }

    public function testChanstatsAndGeoipFlags(): void
    {
        $c = Config::fromArray(['chanstats' => ['dsn' => 'mysql:host=a'], 'geoip' => ['database' => '/x.mmdb']]);
        self::assertTrue($c->chanstatsEnabled());
        self::assertTrue($c->geoipEnabled());
    }
}
