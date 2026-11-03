<?php

declare(strict_types=1);

namespace Magirc\Console;

use Magirc\Services;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Downloads the current GeoLite2 database from MaxMind into the configured
 * path. Needs a free MaxMind account: geoip.account_id and geoip.license_key.
 * Run weekly from cron; MaxMind refreshes GeoLite2 twice a week.
 */
final class GeoipUpdateCommand extends Command
{
    private const BASE = 'https://download.maxmind.com/geoip/databases/%s/download?suffix=tar.gz';

    public function __construct(private readonly string $rootDir)
    {
        parent::__construct('geoip:update');
    }

    protected function configure(): void
    {
        $this->setDescription('Download or refresh the MaxMind GeoLite2 database configured in geoip.database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cfg = Services::fromRoot($this->rootDir)->config;
        $target = $cfg->string('geoip.database');
        $account = $cfg->string('geoip.account_id');
        $key = $cfg->string('geoip.license_key');
        $edition = $cfg->string('geoip.edition', 'GeoLite2-Country');

        if ($target === '') {
            $output->writeln('<error>geoip.database is not set; nothing to update.</error>');
            return Command::FAILURE;
        }
        if ($account === '' || $key === '') {
            $output->writeln('<error>geoip.account_id and geoip.license_key are required.</error>');
            $output->writeln('Create a free account at https://www.maxmind.com/en/geolite2/signup and generate a license key.');
            return Command::FAILURE;
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            $output->writeln("<error>Cannot create $dir</error>");
            return Command::FAILURE;
        }

        $url = sprintf(self::BASE, rawurlencode($edition));
        $auth = 'Authorization: Basic ' . base64_encode($account . ':' . $key) . "\r\n";
        $context = stream_context_create(['http' => ['header' => $auth, 'timeout' => 120, 'follow_location' => 1]]);

        $output->writeln("Downloading $edition ...");
        $archive = @file_get_contents($url, false, $context);
        if ($archive === false) {
            $err = error_get_last();
            $output->writeln('<error>Download failed: ' . ($err['message'] ?? 'unknown error') . '</error>');
            return Command::FAILURE;
        }
        $expected = @file_get_contents($url . '.sha256', false, $context);
        if (is_string($expected) && preg_match('/^([0-9a-f]{64})/i', trim($expected), $m)) {
            if (!hash_equals(strtolower($m[1]), hash('sha256', $archive))) {
                $output->writeln('<error>Checksum mismatch; the download is corrupt.</error>');
                return Command::FAILURE;
            }
        } else {
            $output->writeln('<comment>Could not fetch the SHA-256 file; continuing without verification.</comment>');
        }

        $tmpDir = sys_get_temp_dir() . '/magirc-geoip-' . getmypid();
        @mkdir($tmpDir, 0700, true);
        $tgz = "$tmpDir/db.tar.gz";
        file_put_contents($tgz, $archive);
        unset($archive);

        try {
            $phar = new \PharData($tgz);
            $mmdb = null;
            foreach (new \RecursiveIteratorIterator($phar) as $file) {
                if (str_ends_with($file->getFilename(), '.mmdb')) {
                    $mmdb = $file;
                    break;
                }
            }
            if ($mmdb === null) {
                throw new \RuntimeException('No .mmdb file inside the archive.');
            }
            $tmpTarget = $target . '.tmp';
            if (@copy($mmdb->getPathname(), $tmpTarget) === false || !@rename($tmpTarget, $target)) {
                throw new \RuntimeException("Cannot write $target");
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            @unlink($tgz);
            @rmdir($tmpDir);
        }

        $output->writeln("<info>Updated $target</info>");
        return Command::SUCCESS;
    }
}
