<?php

declare(strict_types=1);

namespace Magirc;

/**
 * Typed access to conf/magirc.php merged over the defaults in
 * conf/magirc.example.php, so a config file only needs the keys it changes.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function load(string $confDir): self
    {
        $defaults = require $confDir . '/magirc.example.php';
        $file = $confDir . '/magirc.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Missing configuration file $file. Copy magirc.example.php and edit it.");
        }
        $local = require $file;
        if (!is_array($local)) {
            throw new \RuntimeException("$file must return an array.");
        }
        return new self(self::merge($defaults, $local));
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $defaults = require __DIR__ . '/../conf/magirc.example.php';
        return new self(self::merge($defaults, $data));
    }

    /**
     * Dotted path lookup: get('anope.token').
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }

    public function string(string $path, string $default = ''): string
    {
        $v = $this->get($path, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    public function int(string $path, int $default = 0): int
    {
        $v = $this->get($path, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $path, bool $default = false): bool
    {
        $v = $this->get($path, $default);
        return is_bool($v) ? $v : (bool) $default;
    }

    /** @return list<string> */
    public function list(string $path): array
    {
        $v = $this->get($path, []);
        return is_array($v) ? array_values(array_map('strval', $v)) : [];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function chanstatsEnabled(): bool
    {
        return $this->string('chanstats.dsn') !== '';
    }

    public function geoipEnabled(): bool
    {
        return $this->string('geoip.database') !== '';
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($v)) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }
}
