<?php

declare(strict_types=1);

namespace Magirc\Tests;

use Magirc\Config;
use Magirc\Services;
use Magirc\Web\TemplateExtension;
use Magirc\Web\Translator;
use PHPUnit\Framework\TestCase;

final class TemplateExtensionTest extends TestCase
{
    private function ext(string $webchat): TemplateExtension
    {
        $services = new Services(Config::fromArray(['network' => ['webchat' => $webchat]]), dirname(__DIR__));
        return new TemplateExtension($services, Translator::load(dirname(__DIR__) . '/locale', 'en_US'));
    }

    public function testWebchatUrlDropsEmptyParameters(): void
    {
        $e = $this->ext('https://chat.example.org/?join=%channel%');
        self::assertSame('https://chat.example.org/', $e->webchatUrl());
        self::assertSame('https://chat.example.org/?join=%23chat', $e->webchatUrl('#chat'));

        $e = $this->ext('https://chat.example.org/?nick=%nick%&join=%channel%');
        self::assertSame('https://chat.example.org/', $e->webchatUrl());
        self::assertSame('https://chat.example.org/?join=%23chat', $e->webchatUrl('#chat'));
        self::assertSame('https://chat.example.org/?nick=bob&join=%23chat', $e->webchatUrl('#chat', 'bob'));

        $e = $this->ext('https://chat.example.org/%channel%');
        self::assertSame('https://chat.example.org/', $e->webchatUrl());
        self::assertSame('https://chat.example.org/%23chat', $e->webchatUrl('#chat'));

        self::assertSame('', $this->ext('')->webchatUrl('#chat'));
    }

    public function testDatetimeNamesTheZone(): void
    {
        $e = $this->ext('');
        self::assertSame('10 Oct 2019, 09:09 UTC', $e->datetime(1570698581, 'd MMM yyyy, HH:mm'));
        self::assertSame('2019-10-10 09:09 UTC', $e->datetime(1570698581));
        self::assertSame('10 Oct 2019', $e->datetime(1570698581, 'd MMM yyyy'), 'no zone without a time of day');
        self::assertSame("10 Oct 'h'", $e->datetime(1570698581, "d MMM '''h'''"), 'quoted letters are not a time');
        self::assertSame('', $e->datetime(0));

        $services = new Services(Config::fromArray(['display' => ['timezone' => 'Europe/Lisbon']]), dirname(__DIR__));
        $e = new TemplateExtension($services, Translator::load(dirname(__DIR__) . '/locale', 'en_US'));
        self::assertStringStartsWith('2019-10-10 10:09 ', $e->datetime(1570698581));
    }

    public function testIrcStrip(): void
    {
        self::assertSame('hello world', TemplateExtension::ircStrip("\x0304,01hello\x03 \x02world\x02\x0f"));
        self::assertSame('plain', TemplateExtension::ircStrip('plain'));
        self::assertSame('', TemplateExtension::ircStrip(null));
    }

    public function testDuration(): void
    {
        $e = $this->ext('');
        self::assertSame('45s', $e->duration(45));
        self::assertSame('2m', $e->duration(150));
        self::assertSame('1d 2h', $e->duration(86400 + 7200 + 60));
    }
}
