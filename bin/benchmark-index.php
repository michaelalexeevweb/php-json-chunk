#!/usr/bin/env php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * What an index and parallel workers buy, on the same records bin/benchmark.php reads:
 * building the index, reading entry N with and without it, and one pass with 1 to 12 workers, each
 * record encoded and hashed — light work, as any real handler's is at the least — handled a record at
 * a time by forEachInParallel(), and a part at a time by readInParallel(). Then the same records as
 * NDJSON, indexed with indexValues().
 *
 * Usage: php bin/benchmark-index.php [--records=5000000] [--workers=1,2,4,6,8,12]
 */

$records = 5_000_000;
$workerCounts = [1, 2, 4, 6, 8, 12];
foreach (array_slice($_SERVER['argv'], 1) as $argument) {
    if (str_starts_with($argument, '--records=')) {
        $records = max(1, (int)substr($argument, strlen('--records=')));
    } elseif (str_starts_with($argument, '--workers=')) {
        $workerCounts = array_values(array_filter(array_map('intval', explode(',', substr($argument, strlen('--workers='))))));
    } elseif ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, "Usage: php bin/benchmark-index.php [--records=5000000] [--workers=1,2,4,6,8,12]\n");
        exit(0);
    }
}

$path = (string)tempnam(sys_get_temp_dir(), 'jidx_');
$handle = fopen($path, 'wb');
if ($handle === false) {
    throw new RuntimeException('Cannot create the dataset.');
}
fwrite($handle, '[');
for ($i = 1; $i <= $records; $i++) {
    fwrite($handle, ($i > 1 ? ',' : '') . sprintf('{"id":%d,"name":"test","surname":"test","createdAt":"2023-01-01T00:00:00.000Z"}', $i));
}
fwrite($handle, ']');
fclose($handle);

$seconds = static fn(int $started): float => (hrtime(true) - $started) / 1e9;
$reader = new JsonChunkReader();
$last = $records - 1;

try {
    printf("%d records, %.1f MB\n\n", $records, filesize($path) / 1048576);

    $started = hrtime(true);
    $index = $reader->index($path);
    printf("%-36s %10.2f s   %d checkpoints, %.0f KB saved\n", 'building the index', $seconds($started), count($index->checkpoints()), strlen($index->toJson()) / 1024);

    $started = hrtime(true);
    $reader->getNth($path, $last);
    printf("%-36s %10.2f s\n", sprintf('getNth(%d), no index', $last), $seconds($started));

    $started = hrtime(true);
    $reader->readFrom($index, $last, 1)->current();
    printf("%-36s %10.4f s\n", sprintf('readFrom($index, %d)', $last), $seconds($started));

    echo "\nOne pass, every record encoded and hashed:\n";

    // The best of two: the plain read is what every worker count is measured against.
    $plain = INF;
    for ($round = 0; $round < 2; $round++) {
        $started = hrtime(true);
        foreach ($reader->readGenerator($path) as $item) {
            md5((string)json_encode($item));
        }
        $plain = min($plain, $seconds($started));
    }
    printf("%-36s %10.2f s\n", 'a plain read', $plain);

    if (!function_exists('pcntl_fork')) {
        echo "pcntl is not available: forEachInParallel() not measured.\n";
    } else {
        foreach ($workerCounts as $workers) {
            $started = hrtime(true);
            $reader->forEachInParallel($index, $workers, static function (array $item): void {
                md5((string)json_encode($item));
            });
            $time = $seconds($started);
            printf("%-36s %10.2f s   %.1f× the plain read\n", $workers . ' worker' . ($workers === 1 ? '' : 's'), $time, $plain / $time);
        }

        echo "\nThe same with readInParallel(), each worker given its part and returning how many it read:\n";
        foreach ($workerCounts as $workers) {
            $started = hrtime(true);
            $read = array_sum($reader->readInParallel($index, $workers, static function (Generator $part): int {
                $count = 0;
                foreach ($part as $item) {
                    md5((string)json_encode($item));
                    $count++;
                }

                return $count;
            }));
            $time = $seconds($started);
            if ($read !== $records) {
                throw new RuntimeException(sprintf('%d records read of %d.', $read, $records));
            }
            printf("%-36s %10.2f s   %.1f× the plain read\n", $workers . ' worker' . ($workers === 1 ? '' : 's'), $time, $plain / $time);
        }
    }

    // The same records as NDJSON, one to a line, indexed with indexValues().
    $lines = (string)tempnam(sys_get_temp_dir(), 'jidx_lines_');
    $handle = fopen($lines, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Cannot create the NDJSON dataset.');
    }
    for ($i = 1; $i <= $records; $i++) {
        fwrite($handle, sprintf('{"id":%d,"name":"test","surname":"test","createdAt":"2023-01-01T00:00:00.000Z"}', $i) . "\n");
    }
    fclose($handle);

    try {
        printf("\nThe same records as NDJSON, %.1f MB:\n", filesize($lines) / 1048576);

        $started = hrtime(true);
        $linesIndex = $reader->indexValues($lines);
        printf("%-36s %10.2f s   %d checkpoints\n", 'building the index', $seconds($started), count($linesIndex->checkpoints()));

        $started = hrtime(true);
        $reader->readValues($lines, 1, $last)->current();
        printf("%-36s %10.2f s\n", sprintf('readValues(offset %d), no index', $last), $seconds($started));

        $started = hrtime(true);
        $reader->readFrom($linesIndex, $last, 1)->current();
        printf("%-36s %10.4f s\n", sprintf('readFrom($index, %d)', $last), $seconds($started));

        $plainLines = INF;
        for ($round = 0; $round < 2; $round++) {
            $started = hrtime(true);
            foreach ($reader->readValues($lines) as $item) {
                md5((string)json_encode($item));
            }
            $plainLines = min($plainLines, $seconds($started));
        }
        printf("%-36s %10.2f s\n", 'a plain read', $plainLines);

        if (function_exists('pcntl_fork')) {
            foreach ($workerCounts as $workers) {
                $started = hrtime(true);
                $read = array_sum($reader->readInParallel($linesIndex, $workers, static function (Generator $part): int {
                    $count = 0;
                    foreach ($part as $item) {
                        md5((string)json_encode($item));
                        $count++;
                    }

                    return $count;
                }));
                $time = $seconds($started);
                if ($read !== $records) {
                    throw new RuntimeException(sprintf('%d lines read of %d.', $read, $records));
                }
                printf("%-36s %10.2f s   %.1f× the plain read\n", $workers . ' worker' . ($workers === 1 ? '' : 's'), $time, $plainLines / $time);
            }
        }
    } finally {
        unlink($lines);
    }
} finally {
    unlink($path);
}
