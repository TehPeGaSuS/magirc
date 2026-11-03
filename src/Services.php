<?php

declare(strict_types=1);

namespace Magirc;

use Magirc\Anope\Network;
use Magirc\Anope\RpcClient;
use Magirc\GeoIp\Resolver;
use Magirc\History\Store;
use Magirc\Stats\ChanStats;

/**
 * Builds the application services from the configuration. Shared by the
 * web front controller and the CLI so both wire things the same way.
 */
final class Services
{
    private ?Network $network = null;
    private ?Store $store = null;
    private ?ChanStats $chanstats = null;
    private ?Resolver $geoip = null;

    public function __construct(public readonly Config $config, public readonly string $rootDir)
    {
    }

    public static function fromRoot(string $rootDir): self
    {
        return new self(Config::load($rootDir . '/conf'), $rootDir);
    }

    public function rpc(): RpcClient
    {
        return new RpcClient(
            $this->config->string('anope.url'),
            $this->config->string('anope.token'),
            $this->config->int('anope.timeout', 5),
        );
    }

    public function network(): Network
    {
        return $this->network ??= new Network($this->rpc(), $this->config, $this->cacheDir() . '/network.json');
    }

    public function store(): Store
    {
        if ($this->store === null) {
            $this->store = Store::connect(
                $this->config->string('history.dsn'),
                $this->config->get('history.username'),
                $this->config->get('history.password'),
            );
            $this->store->migrate();
        }
        return $this->store;
    }

    /** Null when the activity statistics are not configured. */
    public function chanstats(): ?ChanStats
    {
        if (!$this->config->chanstatsEnabled()) {
            return null;
        }
        return $this->chanstats ??= ChanStats::connect(
            $this->config->string('chanstats.dsn'),
            $this->config->get('chanstats.username'),
            $this->config->get('chanstats.password'),
            $this->config->string('chanstats.prefix', 'anope_'),
        );
    }

    public function geoip(): Resolver
    {
        return $this->geoip ??= new Resolver($this->config->string('geoip.database'));
    }

    public function cacheDir(): string
    {
        $dir = $this->config->string('app.cache_dir');
        return $dir !== '' ? $dir : $this->rootDir . '/tmp';
    }
}
