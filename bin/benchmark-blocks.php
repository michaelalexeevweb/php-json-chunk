#!/usr/bin/env php
<?php

declare(strict_types=1);

use PhpJsonChunk\BlockSize;
use PhpJsonChunk\JsonChunkReader;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Every BlockSize against the size of one element — what decides whether an element is read the fast
 * way, in C, or by the scanner: a million small records, elements of about 10 KB, elements of about
 * 186 KB. Each read is complete; the time is the best of three, the memory the peak above what was held
 * before the read.
 *
 * Usage: php bin/benchmark-blocks.php
 */

$directory = sys_get_temp_dir() . '/jblocks_' . bin2hex(random_bytes(4));
mkdir($directory);

$documents = [
    'small records' => static function ($handle): void {
        for ($i = 1; $i <= 1_000_000; $i++) {
            fwrite($handle, ($i > 1 ? ',' : '') . sprintf('{"id":%d,"name":"test","surname":"test","createdAt":"2023-01-01T00:00:00.000Z"}', $i));
        }
    },
    '10 KB elements' => static function ($handle): void {
        for ($i = 0; $i < 2000; $i++) {
            fwrite($handle, ($i > 0 ? ',' : '') . json_encode(['id' => $i, 'rows' => array_fill(0, 400, ['k' => 'value-' . $i, 'n' => $i])], JSON_THROW_ON_ERROR));
        }
    },
    '186 KB elements' => static function ($handle): void {
        for ($i = 0; $i < 100; $i++) {
            fwrite($handle, ($i > 0 ? ',' : '') . json_encode(['id' => $i, 'rows' => array_fill(0, 8000, ['k' => 'value-' . $i, 'n' => $i])], JSON_THROW_ON_ERROR));
        }
    },
];

$files = [];
foreach ($documents as $name => $write) {
    $path = $directory . '/' . str_replace(' ', '-', $name) . '.json';
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Cannot create the dataset.');
    }
    fwrite($handle, '[');
    $write($handle);
    fwrite($handle, ']');
    fclose($handle);
    $files[$name] = $path;
}

try {
    foreach ($files as $name => $path) {
        printf("%-16s %.1f MB\n", $name, filesize($path) / 1048576);
    }
    echo "\n", str_pad('BlockSize', 10), implode('', array_map(static fn(string $name): string => str_pad($name, 26, ' ', STR_PAD_LEFT), array_keys($files))), "\n";

    foreach (BlockSize::cases() as $size) {
        $row = str_pad($size->name, 10);
        foreach ($files as $path) {
            $reader = new JsonChunkReader(blockSize: $size);
            $best = INF;
            $peak = 0;
            for ($round = 0; $round < 3; $round++) {
                if (function_exists('memory_reset_peak_usage')) {
                    memory_reset_peak_usage();
                }
                $before = memory_get_usage();
                $started = hrtime(true);
                foreach ($reader->readGenerator($path) as $element) {
                }
                unset($element);
                $best = min($best, (hrtime(true) - $started) / 1e6);
                $peak = max($peak, memory_get_peak_usage() - $before);
            }
            $row .= str_pad(sprintf('%.0f ms · %.2f MB', $best, $peak / 1048576), 26, ' ', STR_PAD_LEFT);
        }
        echo $row, "\n";
    }
} finally {
    foreach ($files as $path) {
        unlink($path);
    }
    rmdir($directory);
}
