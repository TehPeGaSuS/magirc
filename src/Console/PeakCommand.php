<?php

declare(strict_types=1);

namespace Magirc\Console;

use Magirc\History\Store;
use Magirc\Services;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shows the recorded peaks, or sets one by hand. Meant for importing a peak
 * from before MagIRC was installed, such as Anope's "Maximum users" from
 * /msg OperServ STATS, which no RPC method returns:
 *
 *   php bin/magirc peak users 5478 "2019-10-10 11:09:41 +0200"
 */
final class PeakCommand extends Command
{
    public function __construct(private readonly string $rootDir)
    {
        parent::__construct('peak');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Show the recorded peaks, or set one (e.g. Anope\'s "Maximum users")')
            ->addArgument('type', InputArgument::OPTIONAL, 'One of: ' . implode(', ', Store::PEAK_TYPES))
            ->addArgument('count', InputArgument::OPTIONAL, 'The peak value')
            ->addArgument('time', InputArgument::OPTIONAL, 'When it happened: Unix timestamp or a date such as "2019-10-10 11:09:41 +0200"')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Replace a higher recorded peak');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = Services::fromRoot($this->rootDir)->store();
        $type = $input->getArgument('type');
        $peaks = $store->peaks();

        if ($type === null) {
            foreach ($peaks as $name => $peak) {
                $output->writeln(sprintf('%-10s %8d  %s', $name, $peak['count'], $peak['time'] ? date('Y-m-d H:i:s T', $peak['time']) : '-'));
            }
            return Command::SUCCESS;
        }

        if (!in_array($type, Store::PEAK_TYPES, true)) {
            $output->writeln(sprintf('<error>Unknown type "%s". Use one of: %s.</error>', $type, implode(', ', Store::PEAK_TYPES)));
            return Command::INVALID;
        }
        $countArg = (string) $input->getArgument('count');
        $timeArg = (string) $input->getArgument('time');
        if (!ctype_digit($countArg) || (int) $countArg < 1 || $timeArg === '') {
            $output->writeln('<error>Give the count and the time, e.g.: peak users 5478 "2019-10-10 11:09:41 +0200"</error>');
            return Command::INVALID;
        }
        $time = ctype_digit($timeArg) ? (int) $timeArg : strtotime($timeArg);
        if ($time === false || $time <= 0) {
            $output->writeln(sprintf('<error>Cannot read the time "%s".</error>', $timeArg));
            return Command::INVALID;
        }

        $count = (int) $countArg;
        $current = $peaks[$type];
        if ($count < $current['count'] && !$input->getOption('force')) {
            $output->writeln(sprintf('<error>The recorded %s peak is higher (%d on %s). Add --force to replace it.</error>',
                $type, $current['count'], date('Y-m-d H:i:s T', $current['time'])));
            return Command::FAILURE;
        }
        $store->setPeak($type, $count, $time);
        $output->writeln(sprintf('%s peak set to %d on %s.', ucfirst($type), $count, date('Y-m-d H:i:s T', $time)));
        return Command::SUCCESS;
    }
}
