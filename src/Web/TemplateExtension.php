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
            new TwigFunction('icon', [$this, 'icon'], ['is_safe' => ['html']]),
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

    /**
     * Lucide icons (https://lucide.dev, ISC licence), inline so they take
     * the text colour of the theme. Inner SVG markup of each icon.
     */
    private const ICONS = [
        'lock' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'bot' => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
        'moon' => '<path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401"/>',
        'shield-user' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="M6.376 18.91a6 6 0 0 1 11.249.003"/><circle cx="12" cy="11" r="4"/>',
    ];

    /**
     * An icon with its meaning as hover text and as hidden text, so screen
     * readers and the table filter still see the word.
     */
    public function icon(string $name, string $label, string $class = ''): string
    {
        if (!isset(self::ICONS[$name])) {
            return '';
        }
        $label = htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $class = trim('icon icon--' . $name . ' ' . htmlspecialchars($class, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return '<span class="' . $class . '" title="' . $label . '">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . self::ICONS[$name] . '</svg><span class="visually-hidden">' . $label . '</span></span>';
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('t', fn (string $s): string => $this->translator->t($s)),
            new TwigFilter('duration', [$this, 'duration']),
            new TwigFilter('ago', [$this, 'ago']),
            new TwigFilter('since', [$this, 'since']),
            new TwigFilter('datetime', [$this, 'datetime']),
            new TwigFilter('prefix_name', [$this, 'prefixName']),
            new TwigFilter('prefix_rank', [self::class, 'prefixRank']),
            new TwigFilter('irc_modes', fn (array $modes): string => implode(' ', array_map('strval', $modes))),
            new TwigFilter('irc_strip', [self::class, 'ircStrip']),
            new TwigFilter('channel_slug', [self::class, 'channelSlug']),
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
            $diff < 86400 * 365 => [min(11, intdiv($diff, 86400 * 30)), ['%d month ago', '%d months ago']],
            default => [0, null],
        };
        if ($unit !== null) {
            return sprintf($this->translator->plural($unit[0], $unit[1], $n), $n);
        }

        // Over a year: "10 years, 243 days ago", counted on the calendar in
        // the site's time zone, so it matches the date printed next to it.
        [$then, $now] = $this->calendarPair($time, $time + $diff);
        $years = $then->diff($now)->y;
        $days = $then->modify('+' . $years . ' years')->diff($now)->days;
        return sprintf($this->translator->t('%s ago'), $this->joinUnits([['year', $years], ['day', $days]]));
    }

    /**
     * Calendar length from $time to $now in the two largest units:
     * "1 month, 12 days", "5 days, 3 hours", "2 hours, 10 minutes". Months
     * and years are calendar ones, so it agrees with the date printed beside.
     */
    public function since(int|null $time, int|null $now = null): string
    {
        $now ??= time();
        if (!$time || $now - $time < 60) {
            return $this->translator->t('just now');
        }
        [$from, $to] = $this->calendarPair($time, $now);
        $d = $from->diff($to);
        $units = [['year', $d->y], ['month', $d->m], ['day', $d->d], ['hour', $d->h], ['minute', $d->i]];
        while ($units[0][1] === 0) {
            array_shift($units);
        }
        return $this->joinUnits(array_slice($units, 0, 2));
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private function calendarPair(int $from, int $to): array
    {
        $tz = new \DateTimeZone($this->services->config->string('display.timezone', 'UTC'));
        return [(new \DateTimeImmutable('@' . $from))->setTimezone($tz), (new \DateTimeImmutable('@' . $to))->setTimezone($tz)];
    }

    /**
     * "10 years, 3 days" from [['year', 10], ['day', 3]]; units that are 0
     * after the first are left out.
     *
     * @param list<array{string, int}> $units
     */
    private function joinUnits(array $units): string
    {
        $forms = [
            'year' => ['%d year', '%d years'],
            'month' => ['%d month', '%d months'],
            'day' => ['%d day', '%d days'],
            'hour' => ['%d hour', '%d hours'],
            'minute' => ['%d minute', '%d minutes'],
        ];
        $text = '';
        foreach ($units as $i => [$unit, $n]) {
            if ($i > 0 && $n === 0) {
                continue;
            }
            $part = sprintf($this->translator->plural($forms[$unit][0], $forms[$unit][1], $n), $n);
            $text = $text === '' ? $part : sprintf($this->translator->t('%s, %s'), $text, $part);
        }
        return $text;
    }

    /**
     * Absolute date/time in the configured timezone and locale. $pattern is an
     * ICU pattern (e.g. 'd MMM yyyy, HH:mm'), so month names are translated.
     * A pattern with a time of day gets the zone appended ("09:09 UTC"), so
     * visitors elsewhere do not read it as their local time.
     */
    public function datetime(int|null $time, string $pattern = 'yyyy-MM-dd HH:mm'): string
    {
        if (!$time) {
            return '';
        }
        $tz = $this->services->config->string('display.timezone', 'UTC');
        if (preg_match("/[HhkK]/", self::patternLetters($pattern)) && !preg_match('/[zZvVOxX]/', self::patternLetters($pattern))) {
            $pattern .= ' zzz';
        }
        $formatter = new \IntlDateFormatter($this->translator->locale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $tz, null, $pattern);
        return (string) $formatter->format($time);
    }

    /** The pattern letters of an ICU date pattern, without its quoted literals. */
    private static function patternLetters(string $pattern): string
    {
        return (string) preg_replace("/'[^']*'/", '', $pattern);
    }

    /**
     * URL form of a channel name: "#chat" becomes "chat". Names where that
     * would leave nothing or another "#" ("#", "##", "##ChatLand") keep the
     * whole name, which url_for encodes as %23. A slug that starts with "#"
     * is therefore always a whole name (see ChannelsController::channelName).
     */
    public static function channelSlug(string $name): string
    {
        $rest = substr($name, 1);
        return str_starts_with($name, '#') && $rest !== '' && !str_starts_with($rest, '#') ? $rest : $name;
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
