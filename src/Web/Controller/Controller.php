<?php

declare(strict_types=1);

namespace Magirc\Web\Controller;

use Magirc\Anope\Snapshot;
use Magirc\Services;
use Magirc\Web\App;
use Magirc\Web\NotFound;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

abstract class Controller
{
    public function __construct(
        protected readonly Services $services,
        protected readonly Twig $twig,
    ) {
    }

    protected function snapshot(): Snapshot
    {
        return $this->services->network()->snapshot();
    }

    /** @param array<string, mixed> $data */
    protected function render(Response $response, string $template, array $data = []): Response
    {
        $data['snapshot_time'] = $this->snapshot()->time;
        return $this->twig->render($response, $template, $data);
    }

    protected function json(Response $response, mixed $data): Response
    {
        return App::json($response, $data);
    }

    protected function notFound(string $message): never
    {
        throw new NotFound($message);
    }

    /** Validated page-size parameter for tables. */
    protected function limit(Request $request, int $default = 10, int $max = 100): int
    {
        $v = (int) ($request->getQueryParams()['limit'] ?? $default);
        return max(1, min($max, $v ?: $default));
    }

    /**
     * Country breakdown of a set of users: [code => [name, count]], sorted
     * by count, with unknown addresses grouped under ''.
     * @param array<string, array<string, mixed>> $users
     * @return list<array{code: string, name: string, count: int, percent: float}>
     */
    protected function countryStats(array $users): array
    {
        $geo = $this->services->geoip();
        $tally = [];
        foreach ($users as $u) {
            $c = $geo->lookup($u['address'] ?? null);
            $key = $c['code'];
            $tally[$key] ??= ['code' => $key, 'name' => $c['name'] !== '' ? $c['name'] : 'Unknown', 'count' => 0];
            $tally[$key]['count']++;
        }
        $total = max(1, count($users));
        usort($tally, static fn (array $a, array $b): int => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);
        foreach ($tally as &$row) {
            $row['percent'] = round($row['count'] * 100 / $total, 1);
        }
        return array_values($tally);
    }
}
