<?php

declare(strict_types=1);

namespace Magirc\Web;

use Gettext\Loader\MoLoader;
use Gettext\Loader\PoLoader;
use Gettext\Translations;
use Gettext\Translator as GettextTranslator;
use Magirc\Services;

/**
 * gettext-based UI translation. Looks for locale/<locale>/LC_MESSAGES/
 * magirc.mo (or .po, compiled on the fly) under the project root.
 * Falls back to the untranslated English strings.
 */
final class Translator
{
    private function __construct(
        private readonly GettextTranslator $translator,
        private readonly string $locale,
    ) {
    }

    public static function fromConfig(Services $services): self
    {
        $locale = $services->config->string('display.locale', 'en_US');
        return self::load($services->rootDir . '/locale', $locale);
    }

    public static function load(string $localeDir, string $locale): self
    {
        $base = "$localeDir/$locale/LC_MESSAGES/magirc";
        $translations = null;
        if (is_file("$base.mo")) {
            $translations = (new MoLoader())->loadFile("$base.mo");
        } elseif (is_file("$base.po")) {
            $translations = (new PoLoader())->loadFile("$base.po");
        }
        $t = $translations instanceof Translations
            ? GettextTranslator::createFromTranslations($translations)
            : new GettextTranslator();
        return new self($t, $locale);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** BCP 47 form for <html lang> and Intl: pt_PT -> pt-PT. */
    public function languageTag(): string
    {
        return str_replace('_', '-', $this->locale);
    }

    public function t(string $message): string
    {
        return $this->translator->gettext($message);
    }

    public function plural(string $singular, string $plural, int $n): string
    {
        return $this->translator->ngettext($singular, $plural, $n);
    }
}
