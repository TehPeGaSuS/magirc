<?php

declare(strict_types=1);

namespace Magirc\GeoIp;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;

/**
 * Country lookup for user addresses from a MaxMind GeoLite2 (Country or
 * City) database file. Results are memoised per request since many users
 * share an address or a /24.
 */
final class Resolver
{
    private ?Reader $reader = null;

    /** @var array<string, array{code: string, name: string}> */
    private array $cache = [];

    /** @var list<string> GeoLite2 name locales, most wanted first. */
    private array $locales = ['en'];

    /** @var null|callable(string): string Translates an English country name; returns it unchanged when there is no translation. */
    private $translate = null;

    public function __construct(private readonly string $databasePath)
    {
    }

    /**
     * Country names in this language. A translation of the English name in
     * the site's .po file wins (that is how pt_PT fixes the names GeoLite2
     * only has in pt-BR); otherwise the database's own localized name is
     * used (GeoLite2 ships de, en, es, fr, ja, pt-BR, ru, zh-CN), then English.
     */
    public function setLocale(string $locale, ?callable $translate = null): void
    {
        $this->translate = $translate;
        $tag = str_replace('_', '-', $locale);
        $lang = strtolower(explode('-', $tag)[0]);
        $candidates = [$tag, $lang];
        if ($lang === 'pt') {
            $candidates[] = 'pt-BR';
        } elseif ($lang === 'zh') {
            $candidates[] = 'zh-CN';
        }
        $candidates[] = 'en';
        $wanted = array_values(array_unique($candidates));
        if ($wanted !== $this->locales) {
            $this->locales = $wanted;
            $this->reader = null;
            $this->cache = [];
        }
    }

    public function enabled(): bool
    {
        return $this->databasePath !== '' && is_readable($this->databasePath);
    }

    /**
     * @return array{code: string, name: string} ISO code (lower case, '' when unknown) and English name.
     */
    public function lookup(?string $address): array
    {
        $unknown = ['code' => '', 'name' => ''];
        if ($address === null || $address === '' || !$this->enabled()) {
            return $unknown;
        }
        if (isset($this->cache[$address])) {
            return $this->cache[$address];
        }
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $this->cache[$address] = $unknown;
        }
        try {
            $this->reader ??= new Reader($this->databasePath, $this->locales);
            $record = $this->reader->country($address);
            $code = strtolower((string) $record->country->isoCode);
            $english = (string) ($record->country->names['en'] ?? $record->country->name);
            $name = (string) $record->country->name;
            if ($this->translate !== null && $english !== '') {
                $translated = ($this->translate)($english);
                if ($translated !== $english) {
                    $name = $translated;
                }
            }
            return $this->cache[$address] = $code === '' ? $unknown : ['code' => $code, 'name' => $name];
        } catch (AddressNotFoundException) {
            return $this->cache[$address] = $unknown;
        } catch (\Throwable) {
            return $this->cache[$address] = $unknown;
        }
    }

    /** Metadata of the loaded database, for the check command. */
    public function describe(): string
    {
        if (!$this->enabled()) {
            return 'not configured';
        }
        try {
            $this->reader ??= new Reader($this->databasePath, $this->locales);
            $meta = $this->reader->metadata();
            return sprintf('%s, built %s', $meta->databaseType, date('Y-m-d', $meta->buildEpoch));
        } catch (\Throwable $e) {
            return 'unreadable: ' . $e->getMessage();
        }
    }
}
