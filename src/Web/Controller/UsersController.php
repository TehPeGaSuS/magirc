<?php

declare(strict_types=1);

namespace Magirc\Web\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UsersController extends Controller
{
    public function index(Request $request, Response $response): Response
    {
        return $this->render($response, 'users.twig', [
            'title' => 'Users',
            'users' => $this->list(),
            'total' => count($this->snapshot()->countedUsers()),
            'hidden' => $this->snapshot()->hiddenCounts(),
        ]);
    }

    /** @param array{nick: string} $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $snapshot = $this->snapshot();
        $user = $snapshot->user($args['nick']);
        if ($user === null || $user['hidden']) {
            $this->notFound('Nobody called "' . $args['nick'] . '" is online right now.');
        }
        $stats = $this->services->chanstats();
        $account = $user['account']['display'] ?? null;

        return $this->render($response, 'user.twig', [
            'title' => $user['nick'],
            'user' => $user,
            'country' => $this->services->geoip()->lookup($user['address'] ?? null),
            'channels' => $snapshot->userChannels($user['nick']),
            'has_stats' => $stats !== null && $account !== null && $stats->userHasStats($account),
        ]);
    }

    public function api(Request $request, Response $response): Response
    {
        return $this->json($response, $this->list());
    }

    /** @return list<array<string, mixed>> */
    private function list(): array
    {
        $geo = $this->services->geoip();
        $rows = [];
        foreach ($this->snapshot()->visibleUsers() as $u) {
            $country = $geo->lookup($u['address'] ?? null);
            $rows[] = [
                'nick' => $u['nick'],
                'ident' => (string) ($u['ident'] ?? ''),
                'host' => (string) ($u['vhost'] ?? $u['chost'] ?? $u['host'] ?? ''),
                'real' => (string) ($u['real'] ?? ''),
                'server' => (string) ($u['server'] ?? ''),
                'server_hidden' => (bool) ($u['server_hidden'] ?? false),
                'account' => $u['account']['display'] ?? null,
                'oper' => $u['oper'],
                'bot' => (bool) ($u['bot'] ?? false),
                'tls' => (bool) ($u['tls'] ?? false),
                'away' => $u['away'] ?? null,
                'signon' => (int) ($u['signon'] ?? 0),
                'channels' => count($u['channels']),
                'country' => $country['code'],
                'country_name' => $country['name'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['nick'], $b['nick']));
        return $rows;
    }
}
