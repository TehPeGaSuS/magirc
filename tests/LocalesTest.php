<?php

declare(strict_types=1);

namespace Magirc\Tests;

use Magirc\Config;
use Magirc\Web\Locales;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class LocalesTest extends TestCase
{
    private function locales(string $default = 'en_US'): Locales
    {
        return new Locales(dirname(__DIR__) . '/locale', Config::fromArray(['display' => ['locale' => $default]]));
    }

    public function testDiscoversTranslations(): void
    {
        $l = $this->locales()->available();
        self::assertSame('English', $l['en_US']);
        self::assertSame('Português', $l['pt_PT']);
        self::assertSame('en_US', array_key_first($l), 'English first');
    }

    public function testListFormOrdersAndLimits(): void
    {
        $l = new Locales(dirname(__DIR__) . '/locale', Config::fromArray(['display' => ['locale' => ['pt_PT', 'en_US', 'xx_XX']]]));
        self::assertSame(['pt_PT', 'en_US'], array_keys($l->available()), 'configured order, missing translations dropped');
        self::assertSame('pt_PT', $l->default());

        $only = new Locales(dirname(__DIR__) . '/locale', Config::fromArray(['display' => ['locale' => ['en_US']]]));
        self::assertSame(['en_US'], array_keys($only->available()), 'pt_PT installed but not offered');
    }

    public function testResolvesCookieThenConfigThenEnglish(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/');
        self::assertSame('pt_PT', $this->locales('pt_PT')->resolve($req));
        self::assertSame('en_US', $this->locales('xx_XX')->resolve($req), 'unknown default falls back to English');
        self::assertSame('pt_PT', $this->locales()->resolve($req->withCookieParams(['magirc_lang' => 'pt_PT'])));
        self::assertSame('en_US', $this->locales()->resolve($req->withCookieParams(['magirc_lang' => '../../etc'])), 'bad cookie ignored');
    }
}
