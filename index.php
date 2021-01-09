<?php

declare(strict_types = 1);

use Doctrine\Common\Cache\FilesystemCache;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\StreamWrapper;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\KeyValueHttpHeader;
use Kevinrob\GuzzleCache\Storage\DoctrineCacheStorage;
use Kevinrob\GuzzleCache\Strategy\GreedyCacheStrategy;

include 'vendor/autoload.php';

// Create default HandlerStack
$stack = HandlerStack::create();

// Add this middleware to the top with `push`
$stack->push(
    new CacheMiddleware(
        new GreedyCacheStrategy(
            new DoctrineCacheStorage(
                new FilesystemCache('/tmp/cache')
            ),
            60 * 60 * 24 * 365, // the TTL in seconds
            new KeyValueHttpHeader(['Authorization']) // Optional - specify the headers that can change the cache key
        )
    ), 'cache'
);

// Initialize the client with the handler option

$client = new Client(
    [
        'base_uri' => 'https://api.travis-ci.com',
        'handler' => $stack,
    ]
);

$options = [
    'headers' => [
        'Authorization' => 'token ' . getenv('TRAVIS_TOKEN'),
        'Travis-API-Version' => 3,
    ],
];
$res = $client->request('GET', 'repos', $options);

$response = $res->getBody()->getContents();
$repos = json_decode($response)->repositories;
$connection = array_filter($repos, fn($repo) => $repo->slug === 'keboola/connection')[0];
$logsPrefix = '/tmp/logs/';
@mkdir($logsPrefix, 0777, true);

$fpCsv = fopen('data.csv', 'w+');
fputcsv(
    $fpCsv, [
        'buildId',
        'startedAt',
        'suite',
        'tests',
        'assertions',
        'year',
        'month',
        'day',
        'date'
    ]
);

$next = $connection->{'@href'} . '/builds?branch.name=master&build.state=passed';
do {
    $res = $client->request('GET', $next, $options);
    $body = $res->getBody()->getContents();
    $builds = json_decode($body);
    $next = null;
    if (!$builds->{'@pagination'}->is_last) {
        $next = $builds->{'@pagination'}->next->{'@href'};
    }

    foreach ($builds->builds as $build) {
        $buildDate = DateTimeImmutable::createFromFormat(DATE_ATOM, $build->started_at);
        echo implode(
                "\t", [
                $build->started_at,
                $build->id,
                $buildDate->format('Y'),
                $buildDate->format('m'),
                $buildDate->format('d'),
                $buildDate->format('Y-m-d H:i:s'),
            ]
            ) . PHP_EOL;
        if ($build->state !== 'passed') {
            continue;
        }
        $res = $client->request('GET', $build->{'@href'} . '/stages?include=stage.jobs', $options);
        $stages = json_decode($res->getBody()->getContents());
        $buildStage = array_values(array_filter($stages->stages, fn($stage) => ($stage->number === 2)))[0];
        if ($buildStage->state === 'canceled') {
            continue;
        }
        $res = $client->request('GET', $buildStage->jobs[0]->{'@href'} . '/log', $options);
        $log = json_decode($res->getBody()->getContents());
        $res = $client->request('GET', $log->{'@raw_log_href'}, $options);

        $stream = StreamWrapper::getResource($res->getBody());
        #$logContents = $res->getBody()->getContents();
        $fp = fopen($logsPrefix . $build->id . '.txt', 'w+');
        $fp2 = fopen($logsPrefix . $build->id . '-filtered.txt', 'w+');
        while (!feof($stream)) {
            $line = fgets($stream);
            $matches = [];
            if (preg_match('/^(?:[:\d]+-UTC - )?([\S]+).*OK \((\d+) tests, (\d+) assertions\)/', $line, $matches)) {
                fwrite($fp2, $line);
                fputcsv(
                    $fpCsv, [
                        $build->id,
                        $build->started_at,
                        $matches[1],
                        $matches[2],
                        $matches[3],
                        $buildDate->format('Y'),
                        $buildDate->format('m'),
                        $buildDate->format('d'),
                        $buildDate->format('Y-m-d H:i:s'),
                    ]
                );
            }
            $matches = [];
            if (preg_match('/^(?:[:\d]+-UTC - )?([\S]+).*Tests: (\d+)\[0m\[30;43m, Assertions: (\d+)/', $line, $matches)) {
                fwrite($fp2, $line);
                fputcsv(
                    $fpCsv, [
                        $build->id,
                        $build->started_at,
                        $matches[1],
                        $matches[2],
                        $matches[3],
                        $buildDate->format('Y'),
                        $buildDate->format('m'),
                        $buildDate->format('d'),
                        $buildDate->format('Y-m-d H:i:s'),
                    ]
                );
            }
            fwrite($fp, $line);
        }
        fclose($fp);
        fclose($fp2);
    }
} while ($next);

register_shutdown_function(
    function () use ($fpCsv, $fp, $fp2) {
        @fclose($fpCsv);
        @fclose($fp);
        @fclose($fp2);
    }
);
