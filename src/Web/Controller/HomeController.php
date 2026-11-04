<?php

declare(strict_types=1);

namespace Magirc\Web\Controller;

use League\CommonMark\CommonMarkConverter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HomeController extends Controller
{
    public function home(Request $request, Response $response): Response
    {
        if ($this->services->config->string('display.home') === 'welcome') {
            return $this->content($request, $response, ['page' => 'welcome']);
        }
        return $this->network($request, $response);
    }

    public function network(Request $request, Response $response): Response
    {
        $snapshot = $this->snapshot();
        $store = $this->services->store();
        $counts = $snapshot->counts();
        $day = $store->history(time() - 86400, 300);
        $week = $store->history(time() - 7 * 86400, 3600);
        $month = $store->history(time() - 30 * 86400, 6 * 3600);

        $servers = $snapshot->visibleServers();
        usort($servers, static fn (array $a, array $b): int => $b['usercount'] <=> $a['usercount']);
        $channels = $snapshot->visibleChannels();
        usort($channels, static fn (array $a, array $b): int => $b['usercount'] <=> $a['usercount']);

        return $this->render($response, 'network.twig', [
            'counts' => $counts,
            'peaks' => $store->peaks(),
            'history' => ['day' => $day, 'week' => $week, 'month' => $month],
            'last_sample' => $store->lastSampleTime(),
            'top_servers' => array_slice($servers, 0, 5),
            'top_channels' => array_slice($channels, 0, 10),
            'operators' => count($snapshot->operators()),
        ]);
    }

    public function countries(Request $request, Response $response): Response
    {
        if (!$this->services->geoip()->enabled()) {
            $this->notFound('Country statistics are not enabled on this site.');
        }
        return $this->render($response, 'countries.twig', [
            'title' => 'Countries',
            'countries' => $this->countryStats($this->snapshot()->visibleUsers()),
            'total' => count($this->snapshot()->visibleUsers()),
        ]);
    }

    /**
     * Users connecting from one country ("unknown" for addresses the
     * database cannot place), respecting the listing rules.
     * @param array{code: string} $args
     */
    public function country(Request $request, Response $response, array $args): Response
    {
        $geo = $this->services->geoip();
        if (!$geo->enabled()) {
            $this->notFound('Country statistics are not enabled on this site.');
        }
        $code = $args['code'] === 'unknown' ? '' : strtolower($args['code']);
        $name = '';
        $users = [];
        foreach ($this->snapshot()->visibleUsers() as $u) {
            $c = $geo->lookup($u['address'] ?? null);
            if ($c['code'] === $code) {
                $users[] = $u;
                $name = $c['name'];
            }
        }
        if ($users === []) {
            $this->notFound('Nobody is connecting from there right now.');
        }
        usort($users, static fn (array $a, array $b): int => strcasecmp($a['nick'], $b['nick']));

        return $this->render($response, 'country.twig', [
            'title' => $name !== '' ? $name : 'Unknown country',
            'code' => $code,
            'name' => $name,
            'users' => $users,
        ]);
    }

    /** @param array{page: string} $args */
    public function content(Request $request, Response $response, array $args): Response
    {
        $page = preg_replace('/[^a-z0-9_-]/i', '', $args['page']);
        $file = $this->services->rootDir . '/content/' . $page . '.md';
        if ($page === '' || !is_file($file)) {
            $this->notFound('There is no page called "' . $args['page'] . '".');
        }
        $markdown = (string) file_get_contents($file);
        $title = preg_match('/^#\s+(.+)$/m', $markdown, $m) ? trim($m[1]) : ucfirst($page);
        $html = (new CommonMarkConverter(['html_input' => 'allow', 'allow_unsafe_links' => false]))->convert($markdown);
        return $this->render($response, 'content.twig', ['title' => $title, 'html' => (string) $html, 'page' => $page]);
    }

    public function apiStatus(Request $request, Response $response): Response
    {
        $snapshot = $this->snapshot();
        return $this->json($response, [
            'time' => $snapshot->time,
            'counts' => $snapshot->counts(),
            'peaks' => $this->services->store()->peaks(),
        ]);
    }

    public function apiHistory(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $range = $q['range'] ?? 'day';
        [$since, $bucket] = match ($range) {
            'week' => [time() - 7 * 86400, 3600],
            'month' => [time() - 30 * 86400, 6 * 3600],
            'year' => [time() - 365 * 86400, 86400],
            default => [time() - 86400, 300],
        };
        $store = $this->services->store();
        if (isset($q['server'])) {
            return $this->json($response, $store->serverHistory((string) $q['server'], $since, $bucket));
        }
        return $this->json($response, $store->history($since, $bucket));
    }

    public function apiCountries(Request $request, Response $response): Response
    {
        return $this->json($response, $this->countryStats($this->snapshot()->visibleUsers()));
    }
}
