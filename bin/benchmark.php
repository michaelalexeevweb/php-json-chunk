#!/usr/bin/env php
<?php

declare(strict_types=1);

use Clue\JsonStream\StreamingJsonParser;
use JsonCollectionParser\Parser as JsonCollectionParser;
use JsonDecodeStream\Parser as JsonDecodeStreamParser;
use JsonMachine\Items;
use JsonStreamer\JsonStreamer;
use JsonStreamingParser\Listener\ListenerInterface;
use JsonStreamingParser\Parser as SalsifyParser;
use PhpJsonChunk\JsonChunkReader;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

/**
 * Counts direct elements of a root JSON array while ignoring nested structure depth.
 */
final class RootArrayCountListener implements ListenerInterface
{
  /**
   * @return array{elapsedMs: float, peakDeltaMb: float, total: int}
   */
  function measureCrocodile2uJsonStreamer(string $filePath): array
  {
      return measureExecution(static function () use ($filePath): int {
          $handle = fopen($filePath, 'rb');
          if ($handle === false) {
              throw new RuntimeException(sprintf('Unable to open benchmark file "%s".', $filePath));
          }

          try {
              // crocodile2u/json-streamer expects root to be an array, no path needed
              $streamer = new JsonStreamer($handle);
              $total = 0;

              foreach ($streamer as $item) {
                  $total++;
                  /** @phpstan-ignore-next-line */
                  $_ = $item;
              }

              return $total;
          } finally {
              fclose($handle);
          }
      });
  }

    private int $total = 0;

    /** @var array<int, string> */
    private array $stack = [];

    private bool $insideRootArray = false;

    private int $rootItemDepth = 0;

    public function startDocument(): void
    {
        $this->total = 0;
        $this->stack = [];
        $this->insideRootArray = false;
        $this->rootItemDepth = 0;
    }

    public function endDocument(): void
    {
    }

    public function startObject(): void
    {
        if ($this->isStartingRootArrayItem()) {
            $this->total++;
            $this->rootItemDepth = 1;
        } elseif ($this->rootItemDepth > 0) {
            $this->rootItemDepth++;
        }

        $this->stack[] = 'object';
    }

    public function endObject(): void
    {
        array_pop($this->stack);

        if ($this->rootItemDepth > 0) {
            $this->rootItemDepth--;
        }
    }

    public function startArray(): void
    {
        if ($this->stack === []) {
            $this->insideRootArray = true;
        } elseif ($this->isStartingRootArrayItem()) {
                $this->total++;
            $this->rootItemDepth = 1;
        } elseif ($this->rootItemDepth > 0) {
            $this->rootItemDepth++;
        }

        $this->stack[] = 'array';
    }

    public function endArray(): void
    {
        $wasRootArray = $this->insideRootArray && $this->rootItemDepth === 0 && count($this->stack) === 1;

        array_pop($this->stack);

        if ($this->rootItemDepth > 0) {
            $this->rootItemDepth--;
        }

        if ($wasRootArray) {
            $this->insideRootArray = false;
        }
    }

    public function key(string $key): void
    {
    }

    public function value($value)
    {
        if ($this->isStartingRootArrayItem()) {
            $this->total++;
        }
    }

    public function whitespace(string $whitespace): void
    {
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    private function isStartingRootArrayItem(): bool
    {
        return $this->insideRootArray
            && $this->rootItemDepth === 0
            && count($this->stack) === 1
            && $this->stack[0] === 'array';
    }
}

/**
 * @param array<int, string> $argv
 *
 * @return array{runs: int, sizes: array<int, int>}
 */
function parseOptions(array $argv): array
{
    $runs = 3;
    $sizes = [10_000, 30_000, 50_000, 100_000, 500_000, 1_000_000];

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

    fwrite($fh, '[');
    for ($i = 1; $i <= $count; $i++) {
        if ($i > 1) {
            fwrite($fh, ',');
        }

        fwrite($fh, sprintf(
            '{"id":%d,"name":"test","surname":"test","createdAt":"2023-01-01T00:00:00.000Z"}',
            $i,
        ));
    }
    fwrite($fh, ']');
    fclose($fh);

    return $path;
}

/**
 * @return array{elapsedMs: float, peakDeltaMb: float, total: int}
 */
function measureExecution(callable $callback): array
{
    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $memStart = memory_get_usage(false);
    $timeStart = hrtime(true);

    $total = $callback();

    return [
        'elapsedMs' => (hrtime(true) - $timeStart) / 1_000_000,
        'peakDeltaMb' => max(0, (memory_get_peak_usage(false) - $memStart) / 1024 / 1024),
        'total' => $total,
    ];
}

/**
 * @return array{elapsedMs: float, peakDeltaMb: float, total: int}
 */
function measurePhpJsonChunk(string $filePath): array
{
    return measureExecution(static function () use ($filePath): int {
        $reader = new JsonChunkReader();
        $total = 0;

        foreach ($reader->readGenerator($filePath) as $item) {
            $total++;
            /** @phpstan-ignore-next-line */
            $_ = $item;
        }

        return $total;
    });
}

/**
 * @return array{elapsedMs: float, peakDeltaMb: float, total: int}
 */
function measureJsonMachine(string $filePath): array
{
    return measureExecution(static function () use ($filePath): int {
        $total = 0;
        $items = Items::fromFile($filePath);

        foreach ($items as $item) {
            $total++;
            /** @phpstan-ignore-next-line */
            $_ = $item;
        }

        return $total;
    });
}

/**
 * @return array{elapsedMs: float, peakDeltaMb: float, total: int}
 */
function measureSalsify(string $filePath): array
{
    return measureExecution(static function () use ($filePath): int {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open benchmark file "%s".', $filePath));
        }

        try {
            $listener = new RootArrayCountListener();
            $parser = new SalsifyParser($handle, $listener);
            $parser->parse();

            return $listener->getTotal();
        } finally {
            fclose($handle);
        }
    });
}

/**
 * @return array{elapsedMs: float, peakDeltaMb: float, total: int}
 */
function measureJsonDecodeStream(string $filePath): array
{
    return measureExecution(static function () use ($filePath): int {
        $total = 0;
        $parser = JsonDecodeStreamParser::fromFile($filePath);

        foreach ($parser->items('[]', true) as $item) {
            $total++;
            /** @phpstan-ignore-next-line */
            $_ = $item;
        }

        return $total;
    });
}

/**
 * @return array{elapsedMs: float, peakDeltaMb: float, total: int}
 */
function measureJsonCollectionParser(string $filePath): array
{
    return measureExecution(static function () use ($filePath): int {
        $total = 0;
        $parser = new JsonCollectionParser();
        $parser->parse($filePath, static function (array $item) use (&$total): void {
            $total++;
            /** @phpstan-ignore-next-line */
            $_ = $item;
        });

        return $total;
    });
}


/**
 * @param array{elapsedMs: float, peakDeltaMb: float, total: int} $result
 */
function assertExpectedTotal(array $result, int $expectedTotal, string $parserName): void
{
    if ($result['total'] !== $expectedTotal) {
        throw new RuntimeException(sprintf(
            '%s produced invalid total: expected %d, got %d.',
            $parserName,
            $expectedTotal,
            $result['total'],
        ));
    }
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
 * @return array{elapsedMs: float, peakDeltaMb: float, total: int}
 */
function measureCrocodile2uJsonStreamer(string $filePath): array
{
    return measureExecution(static function () use ($filePath): int {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open benchmark file "%s".', $filePath));
        }

        try {
            // crocodile2u/json-streamer needs a path to the array (empty string for root)
            $streamer = new JsonStreamer($handle, '');
            $streamer->setAssoc(true);
            $total = 0;

            foreach ($streamer as $item) {
                $total++;
                /** @phpstan-ignore-next-line */
                $_ = $item;
            }

            return $total;
        } finally {
            fclose($handle);
        }
    });
}

/**
 * @param array<int, int> $sizes
 */
function runBenchmark(int $runs, array $sizes): void
{
    $parsers = [
        'PhpJsonChunk' => static fn(string $filePath): array => measurePhpJsonChunk($filePath),
        'JsonMachine' => static fn(string $filePath): array => measureJsonMachine($filePath),
        'Salsify' => static fn(string $filePath): array => measureSalsify($filePath),
        'JsonDecodeStream' => static fn(string $filePath): array => measureJsonDecodeStream($filePath),
        'JsonCollectionParser' => static fn(string $filePath): array => measureJsonCollectionParser($filePath),
        'Crocodile2uJsonStreamer' => static fn(string $filePath): array => measureCrocodile2uJsonStreamer($filePath),
    ];




    printf("Synthetic benchmark on a common root-array dataset (median of %d runs)\n\n", $runs);
    printf("%-8s  %-22s %-12s %-10s\n", 'Records', 'Parser', 'Time', 'Peak mem');
    echo str_repeat('-', 60) . "\n";

    foreach ($sizes as $count) {
        $filePath = buildDatasetFile($count);

        try {
            $resultsByParser = [
                'PhpJsonChunk' => [],
                'JsonMachine' => [],
                'Salsify' => [],
                'JsonDecodeStream' => [],
                'JsonCollectionParser' => [],
                'Crocodile2uJsonStreamer' => [],
            ];

            // Warm-up each parser once to reduce one-time initialization noise.
            foreach ($parsers as $parserName => $measure) {
                $warmup = $measure($filePath);
                assertExpectedTotal($warmup, $count, $parserName);
            }

            for ($run = 0; $run < $runs; $run++) {
                $runOrder = array_keys($parsers);
                shuffle($runOrder);

                foreach ($runOrder as $parserName) {
                    $result = $parsers[$parserName]($filePath);
                    assertExpectedTotal($result, $count, $parserName);
                    $resultsByParser[$parserName][] = $result;
                }
            }

            $mediansByParser = [];

            foreach (array_keys($parsers) as $parserName) {
                $mediansByParser[$parserName] = [
                    'elapsedMs' => medianMetric($resultsByParser[$parserName], 'elapsedMs'),
                    'peakDeltaMb' => medianMetric($resultsByParser[$parserName], 'peakDeltaMb'),
                ];
            }

            $winner = array_key_first($mediansByParser);
            foreach ($mediansByParser as $parserName => $metrics) {
                if ($metrics['elapsedMs'] < $mediansByParser[$winner]['elapsedMs']) {
                    $winner = $parserName;
                }
            }

            $firstRow = true;
            foreach ($mediansByParser as $parserName => $metrics) {
                printf(
                    "%-8s  %-22s %8.1f ms %6.2f MB\n",
                    $firstRow ? (string) $count : '',
                    $parserName,
                    $metrics['elapsedMs'],
                    $metrics['peakDeltaMb'],
                );
                $firstRow = false;
            }

            printf("%-8s  %-22s %s\n\n", '', 'Winner', $winner);
        } finally {
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }
    }

    echo "Notes:\n";
    echo "- All parsers read the same generated root-array JSON file and iterate items incrementally.\n";
    echo "- Benchmark results depend on hardware, PHP version, and OS. Prefer median values from multiple runs.\n";
}

$options = parseOptions(array_slice($_SERVER['argv'], 1));
runBenchmark($options['runs'], $options['sizes']);
