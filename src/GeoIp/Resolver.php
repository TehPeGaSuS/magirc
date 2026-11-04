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

    /** Locale for country names (ICU/CLDR via intl), e.g. pt_PT. */
    private string $locale = 'en';

    /** @var null|callable(string): string Translates an English country name; returns it unchanged when there is no translation. */
    private $translate = null;

    public function __construct(private readonly string $databasePath)
    {
    }

    /**
     * Country names in this language. The ISO code from the database is
     * turned into a name by ICU's CLDR data, which covers every locale
     * (MaxMind itself only ships eight, and pt-BR rather than pt-PT). A
     * translation of the English name in the site's .po file wins over ICU,
     * for the odd name a translator wants spelled differently.
     */
    public function setLocale(string $locale, ?callable $translate = null): void
    {
        $this->translate = $translate;
        $locale = str_replace('-', '_', $locale);
        if ($locale !== $this->locale) {
            $this->locale = $locale;
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
            $this->reader ??= new Reader($this->databasePath, ['en']);
            $record = $this->reader->country($address);
            $code = strtolower((string) $record->country->isoCode);
            if ($code === '') {
                return $this->cache[$address] = $unknown;
            }
            $english = (string) $record->country->name;
            $name = \Locale::getDisplayRegion('und-' . strtoupper($code), $this->locale);
            if ($name === '' || $name === strtoupper($code)) {
                $name = $english;
            }
            if ($this->translate !== null && $english !== '') {
                $translated = ($this->translate)($english);
                if ($translated !== $english) {
                    $name = $translated;
                }
            }
            return $this->cache[$address] = ['code' => $code, 'name' => $name];
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
            $this->reader ??= new Reader($this->databasePath, ['en']);
            $meta = $this->reader->metadata();
            return sprintf('%s, built %s', $meta->databaseType, date('Y-m-d', $meta->buildEpoch));
        } catch (\Throwable $e) {
            return 'unreadable: ' . $e->getMessage();
        }
    }
}
