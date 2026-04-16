#!/usr/bin/env php
<?php

declare(strict_types=1);

use JsonMachine\Items;
use PhpJsonChunk\JsonChunkReader;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * @param array<int, string> $argv
 *
 * @return array{runs: int, sizes: array<int, int>}
 */
function parseOptions(array $argv): array
{
    $runs = 3;
    $sizes = [10_000, 30_000, 50_000, 100_000];

    foreach ($argv as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            fwrite(
                STDOUT,
                "Usage: php bin/benchmark.php [--runs=3] [--sizes=10000,30000]\n",
            );
            exit(0);
        }

        if (str_starts_with($argument, '--runs=')) {
            $parsedRuns = (int) substr($argument, strlen('--runs='));
            if ($parsedRuns > 0) {
                $runs = $parsedRuns;
            }
            continue;
        }

        if (str_starts_with($argument, '--sizes=')) {
            $sizesRaw = substr($argument, strlen('--sizes='));
            $parsedSizes = [];

            foreach (explode(',', $sizesRaw) as $sizeRaw) {
                $size = (int) trim($sizeRaw);
                if ($size > 0) {
                    $parsedSizes[] = $size;
                }
            }

            if ($parsedSizes !== []) {
                $sizes = $parsedSizes;
            }
        }
    }

    return [
        'runs' => $runs,
        'sizes' => $sizes,
    ];
}

function buildDatasetFile(int $count): string
{
    $path = tempnam(sys_get_temp_dir(), 'jcmp_');
    if ($path === false) {
        throw new RuntimeException('Cannot create temp file.');
    }

    $fh = fopen($path, 'wb');
    if ($fh === false) {
        throw new RuntimeException('Cannot open temp file.');
    }

    fwrite($fh, '{"count":' . $count . ',"data":[');
    for ($i = 1; $i <= $count; $i++) {
        if ($i > 1) {
            fwrite($fh, ',');
        }

        fwrite($fh, sprintf(
            '{"id":%d,"name":"test","surname":"test","createdAt":"2023-01-01T00:00:00.000Z"}',
            $i,
        ));
    }
    fwrite($fh, ']}');
    fclose($fh);

    return $path;
}

/**
 * @return array{elapsedMs: float, peakDeltaMb: float, total: int}
 */
function measurePhpJsonChunk(string $filePath): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $memStart = memory_get_usage(false);
    $timeStart = hrtime(true);

    $reader = new JsonChunkReader();
    $total = 0;

    foreach ($reader->readGenerator($filePath, keyPath: 'data') as $item) {
        $total++;
        /** @phpstan-ignore-next-line */
        $_ = $item;
    }

    return [
        'elapsedMs' => (hrtime(true) - $timeStart) / 1_000_000,
        'peakDeltaMb' => max(0, (memory_get_peak_usage(false) - $memStart) / 1024 / 1024),
        'total' => $total,
    ];
}

/**
 * @return array{elapsedMs: float, peakDeltaMb: float, total: int}
 */
function measureJsonMachine(string $filePath): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $memStart = memory_get_usage(false);
    $timeStart = hrtime(true);

    $total = 0;
    $items = Items::fromFile($filePath, ['pointer' => '/data']);

    foreach ($items as $item) {
        $total++;
        /** @phpstan-ignore-next-line */
        $_ = $item;
    }

    return [
        'elapsedMs' => (hrtime(true) - $timeStart) / 1_000_000,
        'peakDeltaMb' => max(0, (memory_get_peak_usage(false) - $memStart) / 1024 / 1024),
        'total' => $total,
    ];
}

/**
 * @param array<int, array{elapsedMs: float, peakDeltaMb: float, total: int}> $results
 */
function medianMetric(array $results, string $key): float
{
    $values = [];

    foreach ($results as $result) {
        $values[] = (float) $result[$key];
    }

    sort($values);

    return $values[(int) floor(count($values) / 2)];
}

/**
 * @param array<int, int> $sizes
 */
function runBenchmark(int $runs, array $sizes): void
{
    printf("Synthetic benchmark (median of %d runs)\n\n", $runs);
    printf("%-8s  %-12s %-10s  %-12s %-10s  %-11s  %-9s  %s\n", 'Records', 'PC time', 'PC mem', 'JM time', 'JM mem', 'Time delta', 'Time %', 'Speed winner');
    echo str_repeat('-', 100) . "\n";

    foreach ($sizes as $count) {
        $filePath = buildDatasetFile($count);

        try {
            $pcResults = [];
            $jmResults = [];

            for ($run = 0; $run < $runs; $run++) {
                $pcResults[] = measurePhpJsonChunk($filePath);
                $jmResults[] = measureJsonMachine($filePath);
            }

            $pcTime = medianMetric($pcResults, 'elapsedMs');
            $pcMem = medianMetric($pcResults, 'peakDeltaMb');
            $jmTime = medianMetric($jmResults, 'elapsedMs');
            $jmMem = medianMetric($jmResults, 'peakDeltaMb');

            $timeDeltaMs = $pcTime - $jmTime;
            $timeDeltaPct = $jmTime > 0 ? ($timeDeltaMs / $jmTime) * 100 : 0.0;
            $winner = $timeDeltaMs <= 0 ? 'PhpJsonChunk' : 'JsonMachine';

            printf("%-8d  %8.1f ms %6.2f MB  %8.1f ms %6.2f MB  %+9.1f ms  %+7.1f%%  %s\n", $count, $pcTime, $pcMem, $jmTime, $jmMem, $timeDeltaMs, $timeDeltaPct, $winner);
        } finally {
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }
    }

    echo "\nLegend: Delta = PhpJsonChunk - JSON Machine (negative = PhpJsonChunk is faster)\n";
}

$options = parseOptions(array_slice($_SERVER['argv'], 1));
runBenchmark($options['runs'], $options['sizes']);
