<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PhpJsonChunk\JsonChunkReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('performance')]
final class JsonChunkReaderPerformanceTest extends TestCase
{
    #[DataProvider('datasetSizes')]
    public function testReadGeneratorPerformanceOnLargeNestedArray(int $recordsCount): void
    {
        if (getenv('RUN_PERFORMANCE_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_PERFORMANCE_TESTS=1 to run performance tests.');
        }

        $filePath = $this->createDatasetFile($recordsCount);
        $reader = new JsonChunkReader();

        try {
            gc_collect_cycles();
            $peakBefore = memory_get_peak_usage(true);
            $startTime = hrtime(true);

            $total = 0;
            $lastId = null;

            foreach ($reader->readGenerator($filePath, null, null, 0, 'data') as $item) {
                if ($total === 0) {
                    self::assertIsArray($item);
                    self::assertArrayHasKey('id', $item);
                    self::assertArrayHasKey('name', $item);
                    self::assertArrayHasKey('surname', $item);
                    self::assertArrayHasKey('createdAt', $item);
                }

                $total++;
                $lastId = $item['id'];
            }

            $elapsedMs = (hrtime(true) - $startTime) / 1_000_000;
            $peakDeltaMb = (memory_get_peak_usage(true) - $peakBefore) / 1024 / 1024;

            self::assertSame($recordsCount, $total);
            self::assertSame($recordsCount, $lastId);

            fwrite(
                STDERR,
                sprintf(
                    "[performance] records=%d elapsed=%.2fms peakDelta=%.2fMB\n",
                    $recordsCount,
                    $elapsedMs,
                    max(0, $peakDeltaMb),
                ),
            );
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function datasetSizes(): array
    {
        return [
            '10k' => [10_000],
            '30k' => [30_000],
            '50k' => [50_000],
            '100k' => [100_000],
        ];
    }

    private function createDatasetFile(int $recordsCount): string
    {
        $filePath = tempnam(sys_get_temp_dir(), 'json_chunk_perf_');

        if ($filePath === false) {
            self::fail('Unable to create temporary dataset file.');
        }

        $handle = fopen($filePath, 'wb');

        if ($handle === false) {
            @unlink($filePath);
            self::fail('Unable to open temporary dataset file.');
        }

        try {
            fwrite($handle, '{"count":' . $recordsCount . ',"data":[');

            for ($id = 1; $id <= $recordsCount; $id++) {
                if ($id > 1) {
                    fwrite($handle, ',');
                }

                fwrite(
                    $handle,
                    sprintf(
                        '{"id":%d,"name":"test","surname":"test","createdAt":"2023-01-01T00:00:00.000Z"}',
                        $id,
                    ),
                );
            }

            fwrite($handle, ']}');
        } finally {
            fclose($handle);
        }

        return $filePath;
    }
}

