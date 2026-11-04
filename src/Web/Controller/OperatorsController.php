<?php

declare(strict_types=1);

namespace Magirc\Web\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class OperatorsController extends Controller
{
    public function index(Request $request, Response $response): Response
    {
        $geo = $this->services->geoip();
        $rows = [];
        foreach ($this->snapshot()->operators() as $u) {
            $rows[] = [
                'nick' => $u['nick'],
                'host' => (string) ($u['vhost'] ?? $u['chost'] ?? $u['host'] ?? ''),
                'real' => (string) ($u['real'] ?? ''),
                'server' => (string) ($u['server'] ?? ''),
                'server_hidden' => (bool) ($u['server_hidden'] ?? false),
                'account' => $u['account']['display'] ?? null,
                'opertype' => $u['account']['opertype'] ?? null,
                'bot' => (bool) ($u['bot'] ?? false),
                'away' => $u['away'] ?? null,
                'signon' => (int) ($u['signon'] ?? 0),
                'country' => $geo->lookup($u['address'] ?? null)['code'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['nick'], $b['nick']));

        // Services operators (accounts with an oper type) may be offline; list
        // them only when the site owner opted in, since it reveals staff accounts.
        $services = [];
        if (!$this->services->config->bool('display.show_services_opers', false)) {
            return $this->render($response, 'operators.twig', [
                'title' => 'Operators',
                'operators' => $rows,
                'services_opers' => [],
            ]);
        }
        try {
            $opers = $this->services->rpc()->call('anope.listOpers', ['full']);
            if (is_array($opers)) {
                foreach ($opers as $name => $o) {
                    $services[] = [
                        'name' => (string) $name,
                        'type' => is_array($o['opertype'] ?? null) ? (string) ($o['opertype']['name'] ?? '') : (string) ($o['opertype'] ?? ''),
                        'online' => $this->snapshot()->user((string) $name) !== null,
                    ];
                }
            }
        } catch (\Throwable) {
            // Optional: the token may not allow it.
        }

        return $this->render($response, 'operators.twig', [
            'title' => 'Operators',
            'operators' => $rows,
            'services_opers' => $services,
        ]);
    }
}
