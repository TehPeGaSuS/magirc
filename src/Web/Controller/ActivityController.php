<?php

declare(strict_types=1);

namespace Magirc\Web\Controller;

use Magirc\Stats\ChanStats;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Channel activity statistics from Anope's chanstats module. All pages
 * carry a period selector limited to the periods the database can
 * actually provide (see ChanStats::periods()).
 */
final class ActivityController extends Controller
{
    private function stats(): ChanStats
    {
        $stats = $this->services->chanstats();
        if ($stats === null) {
            $this->notFound('Activity statistics are not enabled on this site.');
        }
        return $stats;
    }

    /** @return array{0: string, 1: list<string>} */
    private function period(Request $request, ChanStats $stats): array
    {
        $type = $stats->normalizeType($request->getQueryParams()['period'] ?? 'total');
        return [$type, $stats->periods()];
    }

    public function index(Request $request, Response $response): Response
    {
        $stats = $this->stats();
        [$type, $periods] = $this->period($request, $stats);
        $hidden = array_map('mb_strtolower', $this->services->config->list('display.hide_channels'));
        $limit = $this->limit($request, 20);

        return $this->render($response, 'activity.twig', [
            'title' => 'Activity',
            'period' => $type,
            'periods' => $periods,
            'period_reason' => $stats->status()['reason'],
            'channels' => $stats->topChannels($type, $limit, $hidden),
            'users' => $stats->topUsers($type, $limit),
            'limit' => $limit,
        ]);
    }

    /** @param array{channel: string} $args */
    public function channel(Request $request, Response $response, array $args): Response
    {
        $stats = $this->stats();
        $name = ChannelsController::channelName($args['channel']);
        $hidden = array_map('mb_strtolower', $this->services->config->list('display.hide_channels'));
        if (in_array(mb_strtolower($name), $hidden, true) || !$stats->channelHasStats($name)) {
            $this->notFound('There are no activity statistics for "' . $name . '".');
        }
        [$type, $periods] = $this->period($request, $stats);
        $live = $this->snapshot()->channel($name);

        return $this->render($response, 'activity_channel.twig', [
            'title' => $name,
            'channel' => $name,
            'live' => $live !== null && !$live['hidden'] ? $live : null,
            'period' => $type,
            'periods' => $periods,
            'totals' => $stats->channelTotals($name, $type),
            'users' => $stats->channelUsers($name, $type, $this->limit($request, 50)),
            'hourly' => $stats->hourly($name, '', $type),
        ]);
    }

    /** @param array{account: string} $args */
    public function user(Request $request, Response $response, array $args): Response
    {
        $stats = $this->stats();
        $account = $args['account'];
        if (!$stats->userHasStats($account)) {
            $this->notFound('There are no activity statistics for "' . $account . '".');
        }
        [$type, $periods] = $this->period($request, $stats);
        $hidden = array_map('mb_strtolower', $this->services->config->list('display.hide_channels'));
        $channels = array_values(array_filter(
            $stats->userChannels($account, $type),
            static fn (array $row): bool => !in_array(mb_strtolower((string) $row['channel']), $hidden, true),
        ));

        return $this->render($response, 'activity_user.twig', [
            'title' => $account,
            'account' => $account,
            'live' => $this->snapshot()->user($account),
            'period' => $type,
            'periods' => $periods,
            'totals' => $stats->userTotals($account, $type),
            'channels' => $channels,
            'hourly' => $stats->hourly('', $account, $type),
        ]);
    }
}
