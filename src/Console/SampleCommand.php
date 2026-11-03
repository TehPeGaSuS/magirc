<?php

declare(strict_types=1);

namespace Magirc\Console;

use Magirc\Services;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Takes one sample of the network for the history store. Run from cron every
 * five minutes: "0-59/5 * * * * php /path/to/magirc/bin/magirc sample".
 */
final class SampleCommand extends Command
{
    public function __construct(private readonly string $rootDir)
    {
        parent::__construct('sample');
    }

    protected function configure(): void
    {
        $this->setDescription('Record the current user/channel/server counts in the history store');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $services = Services::fromRoot($this->rootDir);
        $snapshot = $services->network()->snapshot(true);
        $store = $services->store();
        $store->record($snapshot);
        $pruned = $store->prune($services->config->int('history.retention_days', 365));
        $c = $snapshot->counts();
        if ($output->isVerbose()) {
            $output->writeln(sprintf('%s: %d users, %d channels, %d servers, %d operators%s',
                date('Y-m-d H:i:s', $snapshot->time), $c['users'], $c['channels'], $c['servers'], $c['operators'],
                $pruned ? ", pruned $pruned old samples" : ''));
        }
        return Command::SUCCESS;
    }
}
