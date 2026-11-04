<?php

declare(strict_types=1);

namespace Magirc\Tests;

use Magirc\Web\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    public function testFallsBackToEnglish(): void
    {
        $t = Translator::load(dirname(__DIR__) . '/locale', 'xx_XX');
        self::assertSame('Channels', $t->t('Channels'));
        self::assertSame('%s users online.', $t->plural('%s user online.', '%s users online.', 2));
        self::assertSame('xx-XX', $t->languageTag());
    }

    public function testPortuguese(): void
    {
        $t = Translator::load(dirname(__DIR__) . '/locale', 'pt_PT');
        self::assertSame('Canais', $t->t('Channels'));
        self::assertSame('%s utilizador online.', $t->plural('%s user online.', '%s users online.', 1));
        self::assertSame('%s utilizadores online.', $t->plural('%s user online.', '%s users online.', 5));
        self::assertSame('há %d minutos', $t->plural('%d minute ago', '%d minutes ago', 3));
        self::assertSame('dono', $t->t('owner'));
    }
}
