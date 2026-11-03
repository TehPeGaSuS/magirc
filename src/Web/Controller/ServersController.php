<?php

declare(strict_types=1);

namespace Magirc\Web\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ServersController extends Controller
{
    public function index(Request $request, Response $response): Response
    {
        return $this->render($response, 'servers.twig', [
            'title' => 'Servers',
            'servers' => $this->list(),
        ]);
    }

    /** @param array{server: string} $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $snapshot = $this->snapshot();
        $server = $snapshot->server($args['server']);
        $seen = $this->services->store()->serversSeen();
        if (($server === null || $server['hidden']) && !isset($seen[$args['server']])) {
            $this->notFound('There is no server called "' . $args['server'] . '".');
        }
        $name = $server['name'] ?? $args['server'];
        $users = array_filter($snapshot->visibleUsers(), static fn (array $u): bool => strcasecmp((string) $u['server'], $name) === 0);
        usort($users, static fn (array $a, array $b): int => strcasecmp($a['nick'], $b['nick']));
        $store = $this->services->store();

        return $this->render($response, 'server.twig', [
            'title' => $name,
            'server' => $server,
            'online' => $server !== null && !$server['hidden'],
            'seen' => $seen[$name] ?? null,
            'users' => $users,
            'countries' => $this->services->geoip()->enabled() ? $this->countryStats($users) : [],
            'history' => $store->serverHistory($name, time() - 7 * 86400, 3600),
        ]);
    }

    public function api(Request $request, Response $response): Response
    {
        return $this->json($response, $this->list());
    }

    /**
     * Linked servers plus servers seen before but not linked now.
     * @return list<array<string, mixed>>
     */
    private function list(): array
    {
        $snapshot = $this->snapshot();
        $seen = $this->services->store()->serversSeen();
        $rows = [];
        foreach ($snapshot->visibleServers() as $s) {
            $rows[$s['name']] = [
                'name' => $s['name'],
                'description' => (string) ($s['description'] ?? ''),
                'online' => true,
                'users' => $s['usercount'],
                'operators' => $s['opercount'],
                'uplink' => $s['uplink'] ?? null,
                'linked_since' => $seen[$s['name']]['linked_since'] ?? null,
                'last_seen' => null,
            ];
        }
        foreach ($seen as $name => $info) {
            if (!isset($rows[$name])) {
                $rows[$name] = [
                    'name' => $name,
                    'description' => $info['description'],
                    'online' => false,
                    'users' => 0,
                    'operators' => 0,
                    'uplink' => null,
                    'linked_since' => null,
                    'last_seen' => $info['last_seen'],
                ];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$b['online'], $b['users'], $a['name']] <=> [$a['online'], $a['users'], $b['name']]);
        return array_values($rows);
    }
}
