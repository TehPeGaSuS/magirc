<?php

declare(strict_types=1);

namespace Magirc\Tests;

use Magirc\GeoIp\Resolver;
use PHPUnit\Framework\TestCase;

final class ResolverTest extends TestCase
{
    public function testDisabledWithoutDatabase(): void
    {
        $r = new Resolver('');
        self::assertFalse($r->enabled());
        self::assertSame(['code' => '', 'name' => ''], $r->lookup('8.8.8.8'));
        self::assertSame('not configured', $r->describe());
    }

    public function testUnreadableDatabaseIsDisabled(): void
    {
        $r = new Resolver('/nonexistent/GeoLite2-Country.mmdb');
        self::assertFalse($r->enabled());
        self::assertSame(['code' => '', 'name' => ''], $r->lookup('8.8.8.8'));
    }

    public function testLookupsWithRealDatabase(): void
    {
        $path = getenv('MAGIRC_TEST_MMDB') ?: '';
        if ($path === '' || !is_readable($path)) {
            self::markTestSkipped('Set MAGIRC_TEST_MMDB to a GeoLite2-Country.mmdb to run this test.');
        }
        $r = new Resolver($path);
        self::assertTrue($r->enabled());
        self::assertSame('us', $r->lookup('8.8.8.8')['code']);
        self::assertSame('pt', $r->lookup('193.136.1.1')['code']);
        self::assertSame('', $r->lookup('127.0.0.1')['code'], 'private and loopback addresses are skipped');
        self::assertSame('', $r->lookup('10.0.0.1')['code']);
        self::assertSame('', $r->lookup('garbage')['code']);
        self::assertStringStartsWith('GeoLite2-Country, built', $r->describe());
        $r->setLocale('pt_PT');
        self::assertSame('Alemanha', $r->lookup('2a01:4f8::1')['name'], 'names follow the locale');
        $r->setLocale('en_US');
        self::assertSame('Germany', $r->lookup('2a01:4f8::1')['name']);
        $r->setLocale('pt_PT', static fn (string $en): string => $en === 'Germany' ? 'Alemanha (override)' : $en);
        self::assertSame('Alemanha (override)', $r->lookup('2a01:4f8::1')['name'], 'a .po translation of the English name wins');
        self::assertSame('Portugal', $r->lookup('193.136.1.1')['name'], 'untranslated names fall back to the database locale');
    }
}
