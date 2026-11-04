<?php

declare(strict_types=1);

namespace Magirc\Web;

use Magirc\Services;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Template helpers: translation, durations, relative times, country flags
 * and IRC-specific formatting.
 */
final class TemplateExtension extends AbstractExtension
{
    public function __construct(
        private readonly Services $services,
        private readonly Translator $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('__', fn (string $s): string => $this->translator->t($s)),
            new TwigFunction('_n', fn (string $s, string $p, int $n): string => $this->translator->plural($s, $p, $n)),
            new TwigFunction('flag', [$this, 'flag'], ['is_safe' => ['html']]),
            // Plain JSON; Twig's autoescaping turns it into a valid attribute value
            // (quotes become &quot;, which the browser decodes before JSON.parse).
            new TwigFunction('chart_data', fn (mixed $v): string => json_encode($v, JSON_THROW_ON_ERROR)),
            new TwigFunction('webchat_url', [$this, 'webchatUrl']),
        ];
    }

    /**
     * Fills the configured webchat URL template. Placeholders that stay empty
     * are removed together with their query parameter, so a template such as
     * https://chat.example.org/?join=%channel% gives https://chat.example.org/
     * when there is no channel to join.
     */
    public function webchatUrl(?string $channel = null, ?string $nick = null): string
    {
        $template = $this->services->config->string('network.webchat');
        if ($template === '') {
            return '';
        }
        $url = strtr($template, [
            '%channel%' => $channel !== null ? rawurlencode($channel) : '',
            '%nick%' => $nick !== null ? rawurlencode($nick) : '',
        ]);
        // Drop query parameters left without a value ("?join=", "&nick=").
        $parts = parse_url($url);
        if (!isset($parts['query'])) {
            return $url;
        }
        $kept = [];
        foreach (explode('&', $parts['query']) as $pair) {
            if ($pair === '' || str_ends_with($pair, '=')) {
                continue;
            }
            $kept[] = $pair;
        }
        $base = strstr($url, '?', true) ?: $url;
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $base . ($kept ? '?' . implode('&', $kept) : '') . $fragment;
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('t', fn (string $s): string => $this->translator->t($s)),
            new TwigFilter('duration', [$this, 'duration']),
            new TwigFilter('ago', [$this, 'ago']),
            new TwigFilter('datetime', [$this, 'datetime']),
            new TwigFilter('prefix_name', [$this, 'prefixName']),
            new TwigFilter('prefix_rank', [self::class, 'prefixRank']),
            new TwigFilter('irc_modes', fn (array $modes): string => implode(' ', array_map('strval', $modes))),
            new TwigFilter('irc_strip', [self::class, 'ircStrip']),
        ];
    }

    /**
     * Removes mIRC-style formatting: colours (\x03fg[,bg]), bold, italic,
     * underline, strikethrough, monospace, reverse, reset and hex colours.
     */
    public static function ircStrip(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $text = preg_replace('/\x03(\d{1,2}(,\d{1,2})?)?/', '', $text) ?? $text;
        $text = preg_replace('/\x04([0-9a-fA-F]{6}(,[0-9a-fA-F]{6})?)?/', '', $text) ?? $text;
        return preg_replace('/[\x02\x0f\x11\x16\x1d\x1e\x1f]/', '', $text) ?? $text;
    }

    /** "3d 4h", "12m", "45s" */
    public function duration(int|float|null $seconds): string
    {
        $s = (int) ($seconds ?? 0);
        if ($s < 60) {
            return $s . 's';
        }
        $parts = [];
        foreach (['d' => 86400, 'h' => 3600, 'm' => 60] as $unit => $size) {
            if ($s >= $size) {
                $parts[] = intdiv($s, $size) . $unit;
                $s %= $size;
            }
            if (count($parts) === 2) {
                break;
            }
        }
        return implode(' ', $parts);
    }

    /** "5 minutes ago" style text for a unix time. */
    public function ago(int|null $time): string
    {
        if (!$time) {
            return $this->translator->t('never');
        }
        $diff = max(0, time() - $time);
        if ($diff < 60) {
            return $this->translator->t('just now');
        }
        [$n, $unit] = match (true) {
            $diff < 3600 => [intdiv($diff, 60), ['%d minute ago', '%d minutes ago']],
            $diff < 86400 => [intdiv($diff, 3600), ['%d hour ago', '%d hours ago']],
            $diff < 86400 * 30 => [intdiv($diff, 86400), ['%d day ago', '%d days ago']],
            default => [intdiv($diff, 86400 * 30), ['%d month ago', '%d months ago']],
        };
        return sprintf($this->translator->plural($unit[0], $unit[1], $n), $n);
    }

    /**
     * Absolute date/time in the configured timezone and locale. $pattern is an
     * ICU pattern (e.g. 'd MMM yyyy, HH:mm'), so month names are translated.
     */
    public function datetime(int|null $time, string $pattern = 'yyyy-MM-dd HH:mm'): string
    {
        if (!$time) {
            return '';
        }
        $tz = $this->services->config->string('display.timezone', 'UTC');
        $formatter = new \IntlDateFormatter($this->translator->locale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $tz, null, $pattern);
        return (string) $formatter->format($time);
    }

    /** Regional-indicator emoji for an ISO country code; empty when unknown. */
    public function flag(string $code): string
    {
        $code = strtoupper($code);
        if (!preg_match('/^[A-Z]{2}$/', $code)) {
            return '';
        }
        $flag = '';
        foreach (str_split($code) as $ch) {
            $flag .= mb_chr(0x1F1E6 + ord($ch) - ord('A'));
        }
        return '<span class="flag" aria-hidden="true">' . $flag . '</span>';
    }

    /** Sort weight of a status prefix string: owner 5 ... voice 1, none 0. */
    public static function prefixRank(string $prefixes): int
    {
        foreach (['~' => 5, '&' => 4, '@' => 3, '%' => 2, '+' => 1] as $sym => $rank) {
            if (str_contains($prefixes, $sym)) {
                return $rank;
            }
        }
        return 0;
    }

    /** Human name of a status prefix: "@" -> "op". */
    public function prefixName(string $prefixes): string
    {
        $names = ['~' => 'owner', '&' => 'admin', '@' => 'op', '%' => 'halfop', '+' => 'voice'];
        foreach ($names as $sym => $name) {
            if (str_contains($prefixes, $sym)) {
                return $this->translator->t($name);
            }
        }
        return '';
    }
}
