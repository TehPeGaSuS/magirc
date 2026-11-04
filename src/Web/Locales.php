<?php

declare(strict_types=1);

namespace Magirc\Web;

use Magirc\Config;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Which languages exist and which one a visitor gets.
 *
 * Available = English (the source strings) plus every locale/<code>/LC_MESSAGES/
 * magirc.mo|po. The visitor's choice lives in the magirc_lang cookie, set by
 * the /lang/{code} route; without it, display.locale from the config applies.
 */
final class Locales
{
    public const COOKIE = 'magirc_lang';
    public const SOURCE = 'en_US';

    /** @var array<string, string>|null */
    private ?array $available = null;

    public function __construct(
        private readonly string $localeDir,
        private readonly Config $config,
    ) {
    }

    /**
     * display.locale as a list: a string is the default language with every
     * installed translation offered; an array lists the languages to offer,
     * the first one being the default.
     * @return list<string>
     */
    private function configured(): array
    {
        $v = $this->config->get('display.locale', self::SOURCE);
        if (is_array($v)) {
            $codes = array_values(array_filter(array_map('strval', $v), static fn (string $c): bool => $c !== ''));
            return $codes !== [] ? $codes : [self::SOURCE];
        }
        return [is_string($v) && $v !== '' ? $v : self::SOURCE];
    }

    /** The language used when the visitor has not chosen one. */
    public function default(): string
    {
        $default = $this->configured()[0];
        return $this->isAvailable($default) ? $default : self::SOURCE;
    }

    /**
     * Locale code => name in that language ("Português"). With a list in
     * display.locale, in that order; otherwise English first and the rest
     * alphabetical.
     * @return array<string, string>
     */
    public function available(): array
    {
        if ($this->available !== null) {
            return $this->available;
        }
        $list = [self::SOURCE => 'English'];
        foreach (glob($this->localeDir . '/*/LC_MESSAGES/magirc.{mo,po}', GLOB_BRACE) ?: [] as $file) {
            $code = basename(dirname($file, 2));
            if (!preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $code) || isset($list[$code])) {
                continue;
            }
            $name = \Locale::getDisplayLanguage($code, $code);
            $list[$code] = $name !== '' && $name !== $code ? mb_convert_case($name, MB_CASE_TITLE) : $code;
        }
        $wanted = $this->config->get('display.locale');
        if (is_array($wanted)) {
            $ordered = [];
            foreach ($this->configured() as $code) {
                if (isset($list[$code])) {
                    $ordered[$code] = $list[$code];
                }
            }
            return $this->available = $ordered !== [] ? $ordered : [self::SOURCE => 'English'];
        }
        $rest = array_slice($list, 1, null, true);
        asort($rest);
        return $this->available = [self::SOURCE => 'English'] + $rest;
    }

    public function isAvailable(string $code): bool
    {
        return isset($this->available()[$code]);
    }

    /** The locale to use for this request. */
    public function resolve(ServerRequestInterface $request): string
    {
        $cookie = $request->getCookieParams()[self::COOKIE] ?? null;
        if (is_string($cookie) && $this->isAvailable($cookie)) {
            return $cookie;
        }
        return $this->default();
    }
}
