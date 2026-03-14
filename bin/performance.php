#!/usr/bin/env php
<?php

declare(strict_types=1);

use PhpJsonChunk\Tests\Support\JsonChunkPerformanceBenchmark;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * @param array<int, string> $arguments
 */
function parseChunkTempDir(array $arguments): string|null
{
    $argumentsCount = count($arguments);

    for ($index = 0; $index < $argumentsCount; $index++) {
        $argument = $arguments[$index];

        if ($argument === '--help' || $argument === '-h') {
            fwrite(
                STDOUT,
                "Usage: php bin/performance.php [--chunk-temp-dir=path] [--chunk-temp-dir path]\n",
            );

            exit(0);
        }

        if (str_starts_with($argument, '--chunk-temp-dir=')) {
            return substr($argument, strlen('--chunk-temp-dir='));
        }

        if ($argument === '--chunk-temp-dir') {
            return $arguments[$index + 1] ?? null;
        }
    }

    return null;
}

$chunkTempDir = parseChunkTempDir(array_slice($_SERVER['argv'], 1));

$benchmark = new JsonChunkPerformanceBenchmark();

foreach (JsonChunkPerformanceBenchmark::datasetSizes() as [$recordsCount]) {
    $result = $benchmark->run($recordsCount, $chunkTempDir);

    fwrite(
        STDOUT,
        sprintf(
            "[performance] records=%d elapsed=%.2fms peakDelta=%.2fMB file=%s kept=%s\n",
            $result['records'],
            $result['elapsedMs'],
            $result['peakDeltaMb'],
            $result['filePath'],
            $result['keptFile'] ? 'true' : 'false',
        ),
    );
}

