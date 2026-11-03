<?php

declare(strict_types=1);

namespace Magirc\Console;

use Magirc\Anope\RpcException;
use Magirc\Services;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Verifies the installation: configuration, Anope RPC, token rights, the
 * history store, chanstats (and its event scheduler) and GeoIP.
 */
final class CheckCommand extends Command
{
    private bool $failed = false;

    public function __construct(private readonly string $rootDir)
    {
        parent::__construct('check');
    }

    protected function configure(): void
    {
        $this->setDescription('Check the configuration and every connection MagIRC depends on');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->failed = false;

        // Configuration
        try {
            $services = Services::fromRoot($this->rootDir);
        } catch (\Throwable $e) {
            $this->fail($output, 'config', $e->getMessage());
            return Command::FAILURE;
        }
        $this->ok($output, 'config', 'conf/magirc.php loaded');
        $cfg = $services->config;

        $cache = $services->cacheDir();
        if (!is_dir($cache) && !@mkdir($cache, 0775, true)) {
            $this->fail($output, 'cache', "$cache does not exist and cannot be created");
        } elseif (!is_writable($cache)) {
            $this->fail($output, 'cache', "$cache is not writable by " . (get_current_user() ?: 'this user'));
        } else {
            $this->ok($output, 'cache', $cache);
        }

        // Anope RPC
        if ($cfg->string('anope.token') === '') {
            $this->warn($output, 'anope', 'no token configured; only works if the jsonrpc module has no token block');
        }
        try {
            $rpc = $services->rpc();
            $servers = $rpc->call('anope.listServers');
            $this->ok($output, 'anope', sprintf('%s reachable, %d server(s) linked', $cfg->string('anope.url'), is_array($servers) ? count($servers) : 0));
            foreach (['anope.listUsers', 'anope.listChannels', 'anope.listServers', 'anope.user', 'anope.channel', 'anope.server'] as $method) {
                try {
                    $rpc->call($method, $method === 'anope.user' || $method === 'anope.channel' || $method === 'anope.server' ? ['__magirc_probe__'] : []);
                } catch (RpcException $e) {
                    if ($e->isAuthorizationError()) {
                        $this->fail($output, 'anope', "token may not call $method; add anope.* to its methods");
                    }
                    // "does not exist" errors for the probe argument are expected.
                }
            }
            $snapshot = $services->network()->fetch();
            $counts = $snapshot->counts();
            $this->ok($output, 'anope', sprintf('rpc_data works: %d users, %d channels, %d servers, %d operators visible', $counts['users'], $counts['channels'], $counts['servers'], $counts['operators']));
        } catch (\Throwable $e) {
            $this->fail($output, 'anope', $e->getMessage());
        }

        // History store
        try {
            $store = $services->store();
            $last = $store->lastSampleTime();
            $this->ok($output, 'history', $cfg->string('history.dsn') . ($last ? ', last sample ' . date('Y-m-d H:i', $last) : ', no samples yet; add "php bin/magirc sample" to cron'));
        } catch (\Throwable $e) {
            $this->fail($output, 'history', $e->getMessage());
        }

        // chanstats
        if (!$cfg->chanstatsEnabled()) {
            $this->info($output, 'chanstats', 'not configured; activity pages disabled');
        } else {
            try {
                $stats = $services->chanstats();
                if ($stats === null || !$stats->tableExists()) {
                    $this->fail($output, 'chanstats', sprintf('table %schanstats not found; check the prefix and that Anope\'s chanstats module is loaded', $cfg->string('chanstats.prefix')));
                } else {
                    $status = $stats->status();
                    if ($status['periods']) {
                        $this->ok($output, 'chanstats', 'table found, event scheduler ON, daily/weekly/monthly periods available');
                    } else {
                        $this->warn($output, 'chanstats', $status['reason'] . ' Only all-time statistics will be shown. Fix: SET GLOBAL event_scheduler = ON; and event_scheduler = ON under [mysqld] in my.cnf.');
                    }
                }
            } catch (\Throwable $e) {
                $this->fail($output, 'chanstats', $e->getMessage());
            }
        }

        // GeoIP
        if (!$cfg->geoipEnabled()) {
            $this->info($output, 'geoip', 'not configured; country statistics disabled');
        } else {
            $geo = $services->geoip();
            if ($geo->enabled()) {
                $this->ok($output, 'geoip', $geo->describe());
            } else {
                $this->fail($output, 'geoip', $cfg->string('geoip.database') . ' is not readable');
            }
        }

        $output->writeln('');
        $output->writeln($this->failed ? '<error>Some checks failed.</error>' : '<info>All checks passed.</info>');
        return $this->failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function ok(OutputInterface $o, string $what, string $msg): void
    {
        $o->writeln(sprintf('<info>[ OK ]</info> %-10s %s', $what, $msg));
    }

    private function info(OutputInterface $o, string $what, string $msg): void
    {
        $o->writeln(sprintf('[ -- ] %-10s %s', $what, $msg));
    }

    private function warn(OutputInterface $o, string $what, string $msg): void
    {
        $o->writeln(sprintf('<comment>[WARN]</comment> %-10s %s', $what, $msg));
    }

    private function fail(OutputInterface $o, string $what, string $msg): void
    {
        $this->failed = true;
        $o->writeln(sprintf('<error>[FAIL]</error> %-10s %s', $what, $msg));
    }
}
