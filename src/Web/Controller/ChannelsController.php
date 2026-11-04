<?php

declare(strict_types=1);

namespace Magirc\Web\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ChannelsController extends Controller
{
    public function index(Request $request, Response $response): Response
    {
        return $this->render($response, 'channels.twig', [
            'title' => 'Channels',
            'channels' => $this->list(),
            'total' => count($this->snapshot()->countedChannels()),
            'hidden' => $this->snapshot()->hiddenCounts(),
        ]);
    }

    /** @param array{channel: string} $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $name = self::channelName($args['channel']);
        $snapshot = $this->snapshot();
        $channel = $snapshot->channel($name);
        if ($channel === null || !($channel['listed'] ?? !$channel['hidden'])) {
            $this->notFound('There is no channel called "' . $name . '" right now.');
        }
        $members = $snapshot->channelMembers($name);
        usort($members, static function (array $a, array $b): int {
            return [self::rank($b['prefixes']), strtolower($a['nick'])] <=> [self::rank($a['prefixes']), strtolower($b['nick'])];
        });
        $stats = $this->services->chanstats();
        $hasStats = $stats !== null && $stats->channelHasStats($channel['name']);

        return $this->render($response, 'channel.twig', [
            'title' => $channel['name'],
            'channel' => $channel,
            'members' => $members,
            'countries' => $this->services->geoip()->enabled() ? $this->countryStats(array_column($members, 'user')) : [],
            'has_stats' => $hasStats,
        ]);
    }

    public function api(Request $request, Response $response): Response
    {
        return $this->json($response, $this->list());
    }

    /**
     * Reverses TemplateExtension::channelSlug: "chat" is #chat, while a slug
     * that starts with "#" is the whole name ("/channels/%23" is #,
     * "/channels/%23%23ChatLand" is ##ChatLand). Slim has already decoded
     * the path.
     */
    public static function channelName(string $slug): string
    {
        return str_starts_with($slug, '#') ? $slug : '#' . $slug;
    }

    /** @return list<array<string, mixed>> */
    private function list(): array
    {
        $rows = [];
        foreach ($this->snapshot()->visibleChannels() as $c) {
            $rows[] = [
                'name' => $c['name'],
                'users' => $c['usercount'],
                'topic' => $c['topic']['value'] ?? '',
                'modes' => $c['modes'],
                'registered' => (bool) ($c['registered'] ?? false),
                'created' => (int) ($c['created'] ?? 0),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['users'], strtolower($a['name'])] <=> [$a['users'], strtolower($b['name'])]);
        return $rows;
    }

    private static function rank(string $prefixes): int
    {
        foreach (['~' => 5, '&' => 4, '@' => 3, '%' => 2, '+' => 1] as $sym => $rank) {
            if (str_contains($prefixes, $sym)) {
                return $rank;
            }
        }
        return 0;
    }
}
