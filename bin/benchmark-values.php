#!/usr/bin/env php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * A sequence of documents read with readValues(): one to a line (NDJSON), and pretty-printed one after
 * another, against the plain fgets() + json_decode() loop that serves the first. Best of five runs.
 *
 * Usage: php bin/benchmark-values.php [--documents=200000]
 */

$count = 200_000;
foreach (array_slice($_SERVER['argv'], 1) as $argument) {
    if (str_starts_with($argument, '--documents=')) {
        $count = max(1, (int)substr($argument, strlen('--documents=')));
    } elseif ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, "Usage: php bin/benchmark-values.php [--documents=200000]\n");
        exit(0);
    }
}

$lines = (string)tempnam(sys_get_temp_dir(), 'jvals_');
$pretty = (string)tempnam(sys_get_temp_dir(), 'jvals_');
$lineHandle = fopen($lines, 'wb');
$prettyHandle = fopen($pretty, 'wb');
if ($lineHandle === false || $prettyHandle === false) {
    throw new RuntimeException('Cannot create the datasets.');
}
for ($i = 1; $i <= $count; $i++) {
    $document = ['id' => $i, 'name' => 'test', 'surname' => 'test', 'createdAt' => '2023-01-01T00:00:00.000Z'];
    fwrite($lineHandle, json_encode($document, JSON_THROW_ON_ERROR) . "\n");
    fwrite($prettyHandle, json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
}
fclose($lineHandle);
fclose($prettyHandle);

$best = static function (callable $read): float {
    $best = INF;
    for ($round = 0; $round < 5; $round++) {
        $started = hrtime(true);
        $read();
        $best = min($best, (hrtime(true) - $started) / 1e6);
    }

    return $best;
};
$reader = new JsonChunkReader();

try {
    printf("%d documents, %.1f MB one to a line\n\n", $count, filesize($lines) / 1048576);
    printf("%-48s %8.0f ms\n", 'one per line, fgets() + json_decode()', $best(static function () use ($lines): void {
        $handle = fopen($lines, 'rb');
        while ($handle !== false && ($line = fgets($handle)) !== false) {
            json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
        $handle === false ?: fclose($handle);
    }));
    printf("%-48s %8.0f ms\n", 'one per line, readValues()', $best(static function () use ($reader, $lines): void {
        foreach ($reader->readValues($lines) as $ignored) {
        }
    }));
    printf("%-48s %8.0f ms\n", 'pretty-printed one after another, readValues()', $best(static function () use ($reader, $pretty): void {
        foreach ($reader->readValues($pretty) as $ignored) {
        }
    }));
} finally {
    unlink($lines);
    unlink($pretty);
}
